import Darwin
import Foundation
import MothershipBarCore

/// Starts `stack-action.sh` in its own session so the work outlives this app.
struct ActionRunner: Sendable {
    struct Current: Sendable, Equatable {
        var id: String
        var kind: ActionKind
    }

    var config: BarConfig

    private static var currentFile: URL { BarPaths.actions.appending(path: "current") }

    func progressFile(_ id: String) -> URL { BarPaths.actions.appending(path: "\(id).progress.jsonl") }
    func logFile(_ id: String) -> URL { BarPaths.actions.appending(path: "\(id).log") }

    func launch(_ kind: ActionKind) throws -> Current {
        guard let script = Bundle.main.url(forResource: "stack-action", withExtension: "sh") else {
            throw CocoaError(.fileNoSuchFile)
        }
        try FileManager.default.createDirectory(at: BarPaths.actions, withIntermediateDirectories: true)
        let stamp = ISO8601DateFormatter().string(from: Date()).replacingOccurrences(of: ":", with: "")
        let id = "\(stamp)-\(UUID().uuidString.prefix(8).lowercased())"
        try "\(id)\n\(kind.rawValue)\n".write(to: Self.currentFile, atomically: true, encoding: .utf8)
        try spawnDetached(arguments: [
            "/bin/zsh", script.path, id, kind.rawValue, config.wrapperPath, BarPaths.actions.path, config.containerBinary,
            config.craftTool?.python ?? "", config.craftTool?.script ?? "",
            config.deployGuard?.coordinator ?? "", config.deployGuard?.lease ?? "", config.siteURL,
        ])
        BarLog.write("info", "action.launched", loc: "ActionRunner.launch", ctx: ["id": id, "kind": kind.rawValue])
        return Current(id: id, kind: kind)
    }

    /// Restarts one bridge through its own manager. It is not recorded as the panel's current action,
    /// so the Start, Stop and Restart controls stay as they are while it runs.
    func launchBridgeRestart(_ target: BridgeTarget, watch: BridgeWatchConfig) throws -> String {
        guard let script = Bundle.main.url(forResource: "stack-action", withExtension: "sh") else {
            throw CocoaError(.fileNoSuchFile)
        }
        try FileManager.default.createDirectory(at: BarPaths.actions, withIntermediateDirectories: true)
        let stamp = ISO8601DateFormatter().string(from: Date()).replacingOccurrences(of: ":", with: "")
        let id = "\(stamp)-bridge-\(target.label)-\(UUID().uuidString.prefix(8).lowercased())"
        try spawnDetached(arguments: [
            "/bin/zsh", script.path, id, "bridge-restart", config.wrapperPath, BarPaths.actions.path, config.containerBinary,
            config.craftTool?.python ?? "", config.craftTool?.script ?? "",
            config.deployGuard?.coordinator ?? "", config.deployGuard?.lease ?? "", config.siteURL,
            watch.python, watch.source, watch.nativeServices, target.label, target.host, String(target.port),
        ])
        BarLog.write("info", "bridge.restart_launched", loc: "ActionRunner.launchBridgeRestart", ctx: ["id": id, "bridge": target.label])
        return id
    }

    /// The action recorded on disk, for reattaching after the app restarts.
    func recorded() -> Current? {
        guard let text = try? String(contentsOf: Self.currentFile, encoding: .utf8) else { return nil }
        let lines = text.split(separator: "\n").map(String.init)
        guard lines.count == 2, let kind = ActionKind(rawValue: lines[1]) else { return nil }
        return Current(id: lines[0], kind: kind)
    }

    func progress(of id: String) -> ActionProgress {
        parseProgress((try? String(contentsOf: progressFile(id), encoding: .utf8)) ?? "")
    }

    /// True while a runner holds the kernel lock `stack-action.sh` takes on `runner.lock`.
    func runnerIsAlive() -> Bool {
        let descriptor = open(BarPaths.actions.appending(path: "runner.lock").path, O_RDWR)
        guard descriptor >= 0 else { return false }
        defer { close(descriptor) }
        var query = flock()
        query.l_type = Int16(F_WRLCK)
        query.l_whence = Int16(SEEK_SET)
        guard fcntl(descriptor, F_GETLK, &query) == 0 else { return false }
        return query.l_type != Int16(F_UNLCK)
    }

    func lastLogLine(of id: String) -> String? {
        guard let handle = try? FileHandle(forReadingFrom: logFile(id)) else { return nil }
        defer { try? handle.close() }
        let size = (try? handle.seekToEnd()) ?? 0
        try? handle.seek(toOffset: size > 4096 ? size - 4096 : 0)
        let tail = String(decoding: handle.readDataToEndOfFile(), as: UTF8.self)
        return tail.split(separator: "\n").last.map { String($0.prefix(240)) }
    }

    func pruneOldFiles(olderThanDays days: Int = 30) {
        let cutoff = Date().addingTimeInterval(-Double(days) * 86_400)
        let files = (try? FileManager.default.contentsOfDirectory(
            at: BarPaths.actions, includingPropertiesForKeys: [.contentModificationDateKey]
        )) ?? []
        for file in files where file.pathExtension == "log" || file.pathExtension == "jsonl" {
            let modified = (try? file.resourceValues(forKeys: [.contentModificationDateKey]))?.contentModificationDate
            if let modified, modified < cutoff { try? FileManager.default.removeItem(at: file) }
        }
    }

    private func spawnDetached(arguments: [String]) throws {
        var attributes: posix_spawnattr_t?
        posix_spawnattr_init(&attributes)
        defer { posix_spawnattr_destroy(&attributes) }
        posix_spawnattr_setflags(&attributes, Int16(POSIX_SPAWN_SETSID | POSIX_SPAWN_CLOEXEC_DEFAULT))

        var files: posix_spawn_file_actions_t?
        posix_spawn_file_actions_init(&files)
        defer { posix_spawn_file_actions_destroy(&files) }
        for descriptor: Int32 in [0, 1, 2] {
            posix_spawn_file_actions_addopen(&files, descriptor, "/dev/null", descriptor == 0 ? O_RDONLY : O_WRONLY, 0)
        }

        var environment = ProcessInfo.processInfo.environment
        environment["PATH"] = "/opt/homebrew/bin:/usr/bin:/bin:/usr/sbin:/sbin"
        let argv: [UnsafeMutablePointer<CChar>?] = arguments.map { strdup($0) } + [nil]
        let envp: [UnsafeMutablePointer<CChar>?] = environment.map { strdup("\($0.key)=\($0.value)") } + [nil]
        defer {
            argv.forEach { free($0) }
            envp.forEach { free($0) }
        }

        var pid: pid_t = 0
        let status = posix_spawn(&pid, arguments[0], &files, &attributes, argv, envp)
        guard status == 0 else { throw POSIXError(POSIXErrorCode(rawValue: status) ?? .EIO) }
        let child = pid
        DispatchQueue.global(qos: .background).async {
            var exitStatus: Int32 = 0
            waitpid(child, &exitStatus, 0)
        }
    }
}
