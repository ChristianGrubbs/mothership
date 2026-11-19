import Foundation

/// How much work the site still has. Queue sizes count jobs that are waiting as well as running.
public struct QueueReading: Sendable, Equatable {
    public var jobs: Int
    public var uploads: Int
    public init(jobs: Int, uploads: Int) {
        self.jobs = jobs
        self.uploads = uploads
    }
    public var isIdle: Bool { jobs == 0 && uploads == 0 }
}

/// The answer the panel shows before Stop and Restart.
public enum QueueCheck: Sendable, Equatable {
    case reading
    case read(QueueReading)
    case unreadable
}

/// Seconds the panel keeps showing what the last Start or Restart found.
public let siteResultShownFor: TimeInterval = 15 * 60

private let openUploadsKey = "chunk_uploads_open"

/// Reads the helper's last line, `{"idle":bool,"counts":{name:size}}`. Anything else counts as unreadable.
public func parseQueueReading(_ text: String) -> QueueReading? {
    guard let line = text.split(whereSeparator: \.isNewline).last(where: { !$0.allSatisfy(\.isWhitespace) }),
          let body = try? JSONSerialization.jsonObject(with: Data(line.utf8)) as? [String: Any],
          let counts = body["counts"] as? [String: Any], !counts.isEmpty else { return nil }
    var jobs = 0
    var uploads = 0
    for (name, value) in counts {
        guard let number = value as? NSNumber, CFGetTypeID(number) != CFBooleanGetTypeID(),
              number.doubleValue == Double(number.intValue), number.intValue >= 0 else { return nil }
        if name == openUploadsKey { uploads += number.intValue } else { jobs += number.intValue }
    }
    return QueueReading(jobs: jobs, uploads: uploads)
}

/// The line the panel shows after a Start or Restart that the public site has answered.
public func siteResultMessage(for kind: ActionKind, seconds: Int) -> String? {
    let lead: String
    switch kind {
    case .start: lead = "Site started."
    case .restart: lead = "Site restarted."
    default: return nil
    }
    let time = seconds < 60 ? "\(seconds) s" : "\(seconds / 60) min \(seconds % 60) s"
    return "\(lead) It answered after \(time)."
}

extension ConfirmCopy {
    /// Adds what the queue read found. With no read to report, the question stays as it was.
    func adding(_ queues: QueueCheck?, verb: String, anyway: String) -> ConfirmCopy {
        var copy = self
        switch queues {
        case nil:
            break
        case .reading:
            copy.message += " Checking for running jobs."
            copy.isWaiting = true
        case .unreadable:
            copy.message += " The running jobs couldn't be read, so some may be interrupted."
            copy.button = anyway
            copy.isDestructive = true
        case .read(let reading) where reading.isIdle:
            copy.message += " No jobs are waiting or running."
        case .read(let reading):
            copy.message += " \(workInProgress(reading)) \(verb) now can interrupt this work."
            copy.button = anyway
            copy.isDestructive = true
        }
        return copy
    }
}

private func workInProgress(_ reading: QueueReading) -> String {
    let jobs = reading.jobs == 1 ? "1 job is waiting or running" : "\(reading.jobs) jobs are waiting or running"
    let uploads = reading.uploads == 1 ? "1 upload is open" : "\(reading.uploads) uploads are open"
    switch (reading.jobs > 0, reading.uploads > 0) {
    case (true, true): return "\(jobs), and \(uploads)."
    case (true, false): return "\(jobs)."
    default: return "\(uploads)."
    }
}
