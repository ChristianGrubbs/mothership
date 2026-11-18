import Foundation

public struct CraftApp: Decodable, Sendable, Equatable, Identifiable {
    public var app: String
    public var name: String
    public var desktop: String?
    public var engine: String?
    /// Nil when the latest version could not be looked up.
    public var latest: String?
    public var desktopUpdate: Bool
    public var engineUpdate: Bool
    public var desktopRunning: Bool

    public var id: String { app }

    enum CodingKeys: String, CodingKey {
        case app, name, desktop, engine, latest
        case desktopUpdate = "desktop_update"
        case engineUpdate = "engine_update"
        case desktopRunning = "desktop_running"
    }

    public init(
        app: String, name: String, desktop: String?, engine: String?, latest: String?,
        desktopUpdate: Bool, engineUpdate: Bool, desktopRunning: Bool
    ) {
        self.app = app
        self.name = name
        self.desktop = desktop
        self.engine = engine
        self.latest = latest
        self.desktopUpdate = desktopUpdate
        self.engineUpdate = engineUpdate
        self.desktopRunning = desktopRunning
    }
}

public struct CraftMCPStatus: Decodable, Sendable, Equatable {
    public var servers: Int
    public var stale: Int
    public var openSessions: Int
    public var keyRender: Bool

    enum CodingKeys: String, CodingKey {
        case servers, stale
        case openSessions = "open_sessions"
        case keyRender = "key_render"
    }

    public init(servers: Int, stale: Int, openSessions: Int, keyRender: Bool) {
        self.servers = servers
        self.stale = stale
        self.openSessions = openSessions
        self.keyRender = keyRender
    }

    /// Restarting now would end someone's work.
    public var isInUse: Bool { openSessions > 0 || keyRender }
}

public struct CraftStatus: Decodable, Sendable, Equatable {
    public var schema: Int
    public var checkedAt: String
    public var apps: [CraftApp]
    public var mcp: CraftMCPStatus

    enum CodingKeys: String, CodingKey {
        case schema, apps, mcp
        case checkedAt = "checked_at"
    }

    public init(schema: Int = 1, checkedAt: String, apps: [CraftApp], mcp: CraftMCPStatus) {
        self.schema = schema
        self.checkedAt = checkedAt
        self.apps = apps
        self.mcp = mcp
    }

    public var checkedDate: Date? { ISO8601DateFormatter().date(from: checkedAt) }
    public var appsToUpdate: Int { apps.filter { $0.desktopUpdate || $0.engineUpdate }.count }
    public var desktopUpdates: Int { apps.filter(\.desktopUpdate).count }
    public var engineUpdates: Int { apps.filter(\.engineUpdate).count }
    /// Apps with a desktop update that is skipped because the app is open.
    public var openAppsSkipped: Int { apps.filter { $0.desktopUpdate && $0.desktopRunning }.count }
}

/// The tool's result is the last line of its output. Anything else is ignored.
public func parseCraftStatus(_ text: String) -> CraftStatus? {
    guard let line = lastResultLine(text),
          let status = try? JSONDecoder().decode(CraftStatus.self, from: Data(line.utf8)),
          status.schema == 1 else { return nil }
    return status
}

public func lastResultLine(_ text: String) -> String? {
    text.split(whereSeparator: \.isNewline).last { !$0.allSatisfy(\.isWhitespace) }.map(String.init)
}

/// The value on the panel's ArtCraft row.
public func craftRowValue(status: CraftStatus?, isChecking: Bool, checkFailed: Bool) -> String {
    guard let status else { return isChecking ? "Checking" : (checkFailed ? "Can't check" : "Not checked") }
    return status.appsToUpdate == 0 ? "Up to date" : "\(status.appsToUpdate) to update"
}

/// Seconds between update checks. A check asks the network about every app, so it stays rare.
public let craftCheckInterval: TimeInterval = 6 * 3600
public let craftCheckIntervalWhileOpen: TimeInterval = 3600
public let craftCheckRetryInterval: TimeInterval = 600

public func craftCheckIsDue(checkedAt: Date?, lastAttempt: Date?, pageIsOpen: Bool, now: Date) -> Bool {
    if let lastAttempt, now.timeIntervalSince(lastAttempt) < craftCheckRetryInterval { return false }
    guard let checkedAt else { return true }
    return now.timeIntervalSince(checkedAt) >= (pageIsOpen ? craftCheckIntervalWhileOpen : craftCheckInterval)
}

public struct ConfirmCopy: Sendable, Equatable {
    public var title: String
    public var message: String
    public var button: String
    public var isDestructive: Bool
}

/// What the panel says before an action that interrupts someone.
public func confirmCopy(for kind: ActionKind, mcp: CraftMCPStatus?) -> ConfirmCopy {
    switch kind {
    case .stop:
        ConfirmCopy(
            title: "Stop the site?", message: "Visitors get an error until you start it again.",
            button: "Stop Site", isDestructive: true
        )
    case .craftEngines:
        ConfirmCopy(
            title: "Update the engines?",
            message: "The site will be down for about 8 to 12 minutes to stop and start, plus build time for each engine. "
                + "Build time has not been measured, and it will be longer while the Mac is under heavy load.",
            button: "Stop Site and Update", isDestructive: true
        )
    case .craftMCP, .craftMCPForce:
        ConfirmCopy(
            title: "Restart the ArtCraft server?", message: "\(craftWorkInProgress(mcp)) Restarting ends this work.",
            button: "Restart Anyway", isDestructive: true
        )
    default:
        ConfirmCopy(
            title: "Restart the site?", message: "Visitors get an error for about 3 minutes.",
            button: "Restart Site", isDestructive: false
        )
    }
}

private func craftWorkInProgress(_ mcp: CraftMCPStatus?) -> String {
    let sessions = mcp?.openSessions ?? 0
    let open = sessions == 1 ? "1 ArtCraft session is open" : "\(sessions) ArtCraft sessions are open"
    switch (sessions > 0, mcp?.keyRender == true) {
    case (true, true): return "\(open) and a key render is running."
    case (true, false): return "\(open)."
    case (false, true): return "A key render is running."
    case (false, false): return "ArtCraft may be in use."
    }
}
