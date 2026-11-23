import Foundation

/// Exit code the runner uses when stack containers are still running after every way of stopping them.
public let actionStuckContainersExitCode = 85

/// Exit code the runner uses when a recovery finds every stack container already stopped and leaves the stack down.
public let actionStackAlreadyStoppedExitCode = 86

/// What the app does about a drop of the stack's drive on this poll.
public enum DriveRecoveryStep: Sendable, Equatable {
    /// Nothing to do yet: no drop, the drive isn't back long enough, or something else holds the stack.
    case wait
    /// Run the recover action now.
    case recover
    /// Forget the drop without restarting: the stack is healthy again.
    case discard
}

/// Decides when the app restarts the stack by itself. A stack that only looks down is never enough, because
/// a deploy keeps it down on purpose. It takes a drop of the stack's drive while the stack was running,
/// then the drive back and readable for `settle` seconds, then nothing else changing the stack.
public struct DriveRecovery: Sendable, Equatable {
    /// How long the drive must stay readable before the recovery starts, so a mount still settling isn't raced.
    public static let settle: TimeInterval = 20
    /// A drop older than this is forgotten, not acted on. After an hour, someone may have stopped the stack on
    /// purpose by other means, and a stored drop must not override that when the app next opens.
    public static let maxAge: TimeInterval = 60 * 60

    public private(set) var droppedAt: Date?
    public private(set) var readableSince: Date?

    public init(droppedAt: Date? = nil) {
        self.droppedAt = droppedAt
    }

    /// Records a drop. A drop while the stack was already stopped is ignored, since nothing ran on the old mount.
    /// Returns true when the caller should store the drop so it survives an app restart.
    public mutating func driveDropped(stackWasRunning: Bool, at now: Date) -> Bool {
        readableSince = nil
        guard droppedAt == nil else { return false }
        guard stackWasRunning else { return false }
        droppedAt = now
        return true
    }

    /// Feeds one poll's reading of whether the drive can be read.
    public mutating func observe(driveReadable: Bool, at now: Date) {
        guard droppedAt != nil else { return }
        if !driveReadable {
            readableSince = nil
        } else if readableSince == nil {
            readableSince = now
        }
    }

    /// `clear` is false while an action runs, another session changes the stack, or macOS blocks the drive.
    /// `stackHealthy` means the site answers and every container runs.
    public func next(clear: Bool, stackHealthy: Bool, now: Date) -> DriveRecoveryStep {
        guard let droppedAt else { return .wait }
        if now.timeIntervalSince(droppedAt) > Self.maxAge { return .discard }
        guard let readableSince else { return .wait }
        guard now.timeIntervalSince(readableSince) >= Self.settle, clear else { return .wait }
        return stackHealthy ? .discard : .recover
    }

    /// Forgets the drop: once a recovery starts, or when the operator runs an action of their own.
    public mutating func reset() {
        droppedAt = nil
        readableSince = nil
    }
}
