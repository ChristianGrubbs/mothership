import Foundation

/// Seconds between health polls, in every state. A poll makes HTTP requests and reads files only.
public let healthPollInterval: TimeInterval = 30

/// Seconds between the reads that launch a process, while everything is healthy and nobody is looking.
public let processReadIntervalQuiet: TimeInterval = 300

/// Seconds between those reads while the panel is open or something looks wrong.
public let processReadIntervalWatching: TimeInterval = 60

/// Seconds between container memory and uptime reads. These run only while the panel is open.
public let statsReadInterval: TimeInterval = 60

/// What decides whether the process-launching reads run on this poll.
public struct ProcessReadCheck: Sendable, Equatable {
    public var lastRead: Date?
    public var lastSucceeded: Bool
    public var looksHealthy: Bool
    /// The site started or stopped answering since the last read, so the container count is out of date.
    public var siteChanged: Bool
    public var panelIsOpen: Bool
    public var actionRunning: Bool

    public init(
        lastRead: Date?, lastSucceeded: Bool, looksHealthy: Bool, siteChanged: Bool, panelIsOpen: Bool, actionRunning: Bool
    ) {
        self.lastRead = lastRead
        self.lastSucceeded = lastSucceeded
        self.looksHealthy = looksHealthy
        self.siteChanged = siteChanged
        self.panelIsOpen = panelIsOpen
        self.actionRunning = actionRunning
    }
}

public func processReadsAreDue(_ check: ProcessReadCheck, now: Date) -> Bool {
    guard let lastRead = check.lastRead else { return true }
    if check.actionRunning || check.siteChanged { return true }
    let quiet = check.looksHealthy && check.lastSucceeded && !check.panelIsOpen
    return now.timeIntervalSince(lastRead) >= (quiet ? processReadIntervalQuiet : processReadIntervalWatching)
}
