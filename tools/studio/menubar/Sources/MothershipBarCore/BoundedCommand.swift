import Darwin
import Foundation

public struct CommandResult: Sendable {
    public var exitCode: Int32
    public var output: Data
    public var timedOut: Bool
    public var text: String { String(decoding: output, as: UTF8.self) }
    public var succeeded: Bool { exitCode == 0 && !timedOut }
}

public enum BoundedRead: Sendable, Equatable {
    case text(String)
    /// macOS refused the read, or is holding it while a permission question is unanswered.
    case blocked
    case unavailable
}

private final class ResumeOnce: @unchecked Sendable {
    private let lock = NSLock()
    private var continuation: CheckedContinuation<BoundedRead, Never>?
    init(_ continuation: CheckedContinuation<BoundedRead, Never>) { self.continuation = continuation }

    var isDone: Bool {
        lock.lock()
        defer { lock.unlock() }
        return continuation == nil
    }

    func resume(_ value: BoundedRead) {
        lock.lock()
        let waiting = continuation
        continuation = nil
        lock.unlock()
        waiting?.resume(returning: value)
    }
}

/// One blocking read per path. Callers that arrive while it is stalled wait on that same read, so
/// repeated health polls can never pile up blocked workers behind an unanswered privacy question.
private final class SharedReads: @unchecked Sendable {
    private let lock = NSLock()
    private var waiters: [String: [ResumeOnce]] = [:]

    /// Returns true when this caller has to start the read.
    func join(_ path: String, _ waiter: ResumeOnce) -> Bool {
        lock.lock()
        defer { lock.unlock() }
        let isFirst = waiters[path] == nil
        waiters[path, default: []].removeAll(where: \.isDone)
        waiters[path, default: []].append(waiter)
        return isFirst
    }

    func finish(_ path: String, with result: BoundedRead) {
        lock.lock()
        let finished = waiters.removeValue(forKey: path) ?? []
        lock.unlock()
        finished.forEach { $0.resume(result) }
    }

    func isReading(_ path: String) -> Bool {
        lock.lock()
        defer { lock.unlock() }
        return waiters[path] != nil
    }
}

private let sharedReads = SharedReads()

/// True while a blocking read of `path` is still outstanding.
func isFileReadOutstanding(_ path: String) -> Bool { sharedReads.isReading(path) }

private func blockingRead(_ path: String) -> BoundedRead {
    let descriptor = open(path, O_RDONLY)
    guard descriptor >= 0 else { return errno == EPERM || errno == EACCES ? .blocked : .unavailable }
    let handle = FileHandle(fileDescriptor: descriptor, closeOnDealloc: true)
    guard let data = try? handle.readToEnd() else { return errno == EPERM || errno == EACCES ? .blocked : .text("") }
    return .text(String(decoding: data, as: UTF8.self))
}

/// Reads a file without ever hanging the caller: a privacy check can stall a read for minutes.
public func readFile(_ path: String, timeout: TimeInterval) async -> BoundedRead {
    await withCheckedContinuation { continuation in
        let once = ResumeOnce(continuation)
        if sharedReads.join(path, once) {
            DispatchQueue.global(qos: .utility).async {
                sharedReads.finish(path, with: blockingRead(path))
            }
        }
        DispatchQueue.global(qos: .utility).asyncAfter(deadline: .now() + timeout) { once.resume(.blocked) }
    }
}

/// Seconds a timed-out command gets between SIGTERM and SIGKILL.
private let terminateGrace: TimeInterval = 1.5
/// Seconds to wait for output to close after the command itself has exited.
private let outputGrace: TimeInterval = 0.5
/// Seconds after SIGKILL before the caller stops waiting regardless.
private let abandonGrace: TimeInterval = 1
/// Bytes read before the deadlines are checked again, so a noisy command cannot outrun its timeout.
private let maxDrainPerPass = 1 << 20
/// Bytes of output kept. Anything beyond this is read and discarded.
public let maxRetainedOutput = 4 << 20

/// Runs a read-only command in its own process group. Always returns within
/// `timeout` plus a few seconds, even if the command ignores SIGTERM or a child keeps stdout open.
public func runCommand(_ path: String, _ arguments: [String], timeout: TimeInterval) async -> CommandResult {
    await withCheckedContinuation { continuation in
        DispatchQueue.global(qos: .utility).async {
            continuation.resume(returning: runBounded(path: path, arguments: arguments, timeout: timeout))
        }
    }
}

private func runBounded(path: String, arguments: [String], timeout: TimeInterval) -> CommandResult {
    let notStarted = CommandResult(exitCode: -1, output: Data(), timedOut: false)
    var descriptors: [Int32] = [0, 0]
    guard pipe(&descriptors) == 0 else { return notStarted }
    let (readEnd, writeEnd) = (descriptors[0], descriptors[1])
    defer { close(readEnd) }
    _ = fcntl(readEnd, F_SETFL, O_NONBLOCK)
    let spawned = spawnInOwnGroup(path: path, arguments: arguments, stdout: writeEnd)
    close(writeEnd)
    guard let pid = spawned else { return notStarted }

    let started = Date()
    var output = Data()
    var buffer = [UInt8](repeating: 0, count: 65_536)
    var outputClosed = false
    var exitStatus: Int32?
    var exitedAt: TimeInterval?
    var timedOut = false
    var killed = false

    while true {
        let elapsed = Date().timeIntervalSince(started)
        var drained = 0
        while !outputClosed, drained < maxDrainPerPass {
            let count = read(readEnd, &buffer, buffer.count)
            if count > 0 {
                drained += count
                let room = max(0, maxRetainedOutput - output.count)
                output.append(contentsOf: buffer[0..<min(count, room)])
            } else {
                if count == 0 || (errno != EAGAIN && errno != EINTR) { outputClosed = true }
                break
            }
        }
        if exitStatus == nil {
            var status: Int32 = 0
            let reaped = waitpid(pid, &status, WNOHANG)
            if reaped == pid {
                exitStatus = status
                exitedAt = elapsed
            } else if reaped == -1, errno != EINTR {
                exitStatus = -1
                exitedAt = elapsed
            }
        }
        if exitStatus != nil, outputClosed { break }
        if let exitedAt, elapsed - exitedAt >= outputGrace {
            killpg(pid, SIGKILL)
            break
        }
        if elapsed >= timeout, !timedOut {
            timedOut = true
            killpg(pid, SIGTERM)
        }
        if elapsed >= timeout + terminateGrace, !killed {
            killed = true
            killpg(pid, SIGKILL)
        }
        if elapsed >= timeout + terminateGrace + abandonGrace { break }
        var ready = pollfd(fd: readEnd, events: Int16(POLLIN), revents: 0)
        if outputClosed { usleep(50_000) } else { poll(&ready, 1, 100) }
    }

    guard let exitStatus else {
        DispatchQueue.global(qos: .background).async {
            var status: Int32 = 0
            waitpid(pid, &status, 0)
        }
        return CommandResult(exitCode: -1, output: output, timedOut: true)
    }
    return CommandResult(exitCode: decodedExit(exitStatus), output: output, timedOut: timedOut)
}

private func decodedExit(_ status: Int32) -> Int32 {
    guard status != -1 else { return -1 }
    let signal = status & 0x7F
    return signal == 0 ? (status >> 8) & 0xFF : 128 + signal
}

private func spawnInOwnGroup(path: String, arguments: [String], stdout: Int32) -> pid_t? {
    var attributes: posix_spawnattr_t?
    posix_spawnattr_init(&attributes)
    defer { posix_spawnattr_destroy(&attributes) }
    posix_spawnattr_setflags(&attributes, Int16(POSIX_SPAWN_SETPGROUP | POSIX_SPAWN_CLOEXEC_DEFAULT))
    posix_spawnattr_setpgroup(&attributes, 0)

    var files: posix_spawn_file_actions_t?
    posix_spawn_file_actions_init(&files)
    defer { posix_spawn_file_actions_destroy(&files) }
    posix_spawn_file_actions_addopen(&files, 0, "/dev/null", O_RDONLY, 0)
    posix_spawn_file_actions_adddup2(&files, stdout, 1)
    posix_spawn_file_actions_addopen(&files, 2, "/dev/null", O_WRONLY, 0)

    var environment = ProcessInfo.processInfo.environment
    environment["PATH"] = "/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin"
    let argv: [UnsafeMutablePointer<CChar>?] = ([path] + arguments).map { strdup($0) } + [nil]
    let envp: [UnsafeMutablePointer<CChar>?] = environment.map { strdup("\($0.key)=\($0.value)") } + [nil]
    defer {
        argv.forEach { free($0) }
        envp.forEach { free($0) }
    }
    var pid: pid_t = 0
    return posix_spawn(&pid, path, &files, &attributes, argv, envp) == 0 ? pid : nil
}
