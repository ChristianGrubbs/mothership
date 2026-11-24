import Foundation

public enum Overall: String, Sendable, Equatable {
    case checking, online, offline, degraded, starting, stopping, restarting, updating, busy, unconfigured

    public var headline: String {
        switch self {
        case .checking: "Checking"
        case .online: "Online"
        case .offline: "Offline"
        case .degraded: "Needs attention"
        case .starting: "Starting"
        case .stopping: "Stopping"
        case .restarting: "Restarting"
        case .updating: "Updating"
        case .busy: "Busy"
        case .unconfigured: "Not set up"
        }
    }

    public var isChanging: Bool {
        self == .starting || self == .stopping || self == .restarting || self == .updating || self == .busy
    }
}

public enum ActionKind: String, Sendable, Equatable, Codable {
    case start, stop, restart, login
    /// The restart the app runs by itself after the stack's drive drops off and comes back.
    case recover
    case craftApps = "craft-apps"
    case craftEngines = "craft-engines"
    case craftMCP = "craft-mcp"
    case craftMCPForce = "craft-mcp-force"

    public var isCraft: Bool {
        switch self {
        case .craftApps, .craftEngines, .craftMCP, .craftMCPForce: true
        case .start, .stop, .restart, .recover, .login: false
        }
    }

    /// Whether the action stops or starts the stack. An engine update takes the site down while it builds.
    public var changesStack: Bool {
        switch self {
        case .craftApps, .craftMCP, .craftMCPForce: false
        case .start, .stop, .restart, .recover, .login, .craftEngines: true
        }
    }

    public var overall: Overall {
        switch self {
        case .start, .login: .starting
        case .stop: .stopping
        case .restart, .recover: .restarting
        case .craftApps, .craftEngines, .craftMCP, .craftMCPForce: .updating
        }
    }

    public var failureTitle: String {
        switch self {
        case .start, .login: "Start failed"
        case .stop: "Stop failed"
        case .restart: "Restart failed"
        case .recover: "Recovery failed"
        case .craftApps: "App update failed"
        case .craftEngines: "Engine update failed"
        case .craftMCP, .craftMCPForce: "Server restart failed"
        }
    }
}

public struct SiteReading: Sendable, Equatable {
    public var publicStatus: Int?
    public var publicMs: Int?
    public var originStatus: Int?
    public init(publicStatus: Int?, publicMs: Int?, originStatus: Int?) {
        self.publicStatus = publicStatus
        self.publicMs = publicMs
        self.originStatus = originStatus
    }
    public var publicOK: Bool { publicStatus == 200 }
    public var originOK: Bool { originStatus == 200 }
}

public struct ContainerReading: Sendable, Equatable {
    public var running: Int
    public var total: Int
    public var notRunning: [String]
    public init(running: Int, total: Int, notRunning: [String]) {
        self.running = running
        self.total = total
        self.notRunning = notRunning
    }
    public var allRunning: Bool { total > 0 && running == total }
}

public struct TunnelReading: Sendable, Equatable {
    public var jobRunning: Bool
    public var readyConnections: Int?
    public init(jobRunning: Bool, readyConnections: Int?) {
        self.jobRunning = jobRunning
        self.readyConnections = readyConnections
    }
    public var connected: Bool { jobRunning && (readyConnections ?? 0) > 0 }
}

public struct BridgeReading: Sendable, Equatable {
    public var name: String
    public var ok: Bool
    /// Models the bridge says Ollama doesn't list. The bridge still answers, so `ok` stays true.
    public var missingModels: [String]
    public init(name: String, ok: Bool, missingModels: [String] = []) {
        self.name = name
        self.ok = ok
        self.missingModels = missingModels
    }
}

public struct OllamaReading: Sendable, Equatable {
    public var version: String?
    public var loadedModels: Int
    public init(version: String?, loadedModels: Int) {
        self.version = version
        self.loadedModels = loadedModels
    }
}

public struct StatsReading: Sendable, Equatable {
    public var memoryBytes: UInt64?
    public var uptimeSeconds: Int?
    public var diskFreeBytes: Int64?
    public init(memoryBytes: UInt64?, uptimeSeconds: Int?, diskFreeBytes: Int64?) {
        self.memoryBytes = memoryBytes
        self.uptimeSeconds = uptimeSeconds
        self.diskFreeBytes = diskFreeBytes
    }
}

/// A part of the stack whose probe has been failing for too long to trust its last reading.
public enum ReadablePart: String, Sendable, Equatable {
    case containers, bridges
    /// The Linux host that runs the stack. Every part read on it is unknown.
    case host
}

/// Seconds a probe may keep failing before its last good reading is dropped, so one slow poll
/// doesn't flash a warning. Counted from the first failure, because some probes run minutes apart.
public let heldReadingMaxAge: TimeInterval = 90

/// Carries a reading through a brief probe failure, then lets it expire.
public struct HeldReading<Value: Sendable>: Sendable {
    private var value: Value?
    private var failingSince: Date?
    public init() {}

    public mutating func update(_ reading: Value?, at now: Date) -> (value: Value?, unreadable: Bool) {
        if let reading {
            value = reading
            failingSince = nil
            return (reading, false)
        }
        guard value != nil else { return (nil, true) }
        let since = failingSince ?? now
        failingSince = since
        if now.timeIntervalSince(since) <= heldReadingMaxAge { return (value, false) }
        value = nil
        failingSince = nil
        return (nil, true)
    }
}

public struct HealthSnapshot: Sendable, Equatable {
    public var site: SiteReading?
    public var containers: ContainerReading?
    public var tunnel: TunnelReading?
    public var bridges: [BridgeReading]?
    public var ollama: OllamaReading?
    public var stats: StatsReading?
    /// Where the assets live. Never affects the verdict: the read travels a different route from the site's.
    public var store: StoreState
    public var unreadable: [ReadablePart]
    /// macOS has refused, or not yet answered, this app's request to use the stack's drive.
    public var driveBlocked: Bool
    /// What the bridge watcher is doing about each bridge that isn't answering.
    public var bridgeWatch: [String: BridgeWatchStatus] = [:]
    public init(
        site: SiteReading? = nil, containers: ContainerReading? = nil, tunnel: TunnelReading? = nil,
        bridges: [BridgeReading]? = nil, ollama: OllamaReading? = nil, stats: StatsReading? = nil,
        store: StoreState = .checking, unreadable: [ReadablePart] = [], driveBlocked: Bool = false
    ) {
        self.store = store
        self.driveBlocked = driveBlocked
        self.site = site
        self.containers = containers
        self.tunnel = tunnel
        self.bridges = bridges
        self.ollama = ollama
        self.stats = stats
        self.unreadable = unreadable
    }

    var isComplete: Bool { site != nil && containers != nil && tunnel != nil && bridges != nil && ollama != nil }
}

public struct Verdict: Sendable, Equatable {
    public var overall: Overall
    public var reason: String
    public init(overall: Overall, reason: String) {
        self.overall = overall
        self.reason = reason
    }
}

/// Every problem the snapshot shows, most important first.
public func problems(in snapshot: HealthSnapshot) -> [String] {
    var found: [String] = []
    if let site = snapshot.site, !site.publicOK {
        if site.originOK {
            found.append("The site answers on its server but not through the tunnel")
        } else if let status = site.publicStatus {
            found.append("The site returns \(status)")
        } else {
            found.append("The site isn't answering")
        }
    }
    for part in snapshot.unreadable {
        found.append(part == .host ? "Can't reach the server that runs the stack" : "Can't read the \(part.rawValue)")
    }
    if let containers = snapshot.containers, !containers.allRunning {
        found.append("\(containers.running) of \(containers.total) containers running")
    }
    if let tunnel = snapshot.tunnel, !tunnel.connected {
        found.append(tunnel.jobRunning ? "The tunnel isn't connected" : "The tunnel isn't running")
    }
    if snapshot.bridges?.isEmpty == true {
        found.append("No bridges are configured")
    }
    for bridge in snapshot.bridges ?? [] where !bridge.ok {
        found.append(snapshot.bridgeWatch[bridge.name]?.problem(bridge: bridge.name) ?? "The \(bridge.name) bridge isn't answering")
    }
    // An answering bridge can still do no work: on 2026-11-21 Ollama lost its model list and every AI job failed
    // for 29 hours while each bridge read green. Not a restart fault: Ollama runs outside the stack.
    for bridge in snapshot.bridges ?? [] where bridge.ok && !bridge.missingModels.isEmpty {
        let models = bridge.missingModels.joined(separator: " and ")
        found.append("The \(bridge.name) bridge can't find its \(models) model\(bridge.missingModels.count > 1 ? "s" : "") in Ollama")
    }
    if let ollama = snapshot.ollama, ollama.version == nil {
        found.append("Ollama isn't answering")
    }
    return found
}

/// Every problem written out in full for the panel, naming each stopped container.
public func detailedProblems(in snapshot: HealthSnapshot) -> [String] {
    let containerSummary = snapshot.containers.map { "\($0.running) of \($0.total) containers running" }
    return problems(in: snapshot).map { problem in
        guard problem == containerSummary, let names = snapshot.containers?.notRunning, !names.isEmpty else { return problem }
        let stopped = names.map { $0.replacingOccurrences(of: "_", with: " ") }.joined(separator: ", ")
        return "\(problem). Stopped: \(stopped)"
    }
}

public let driveBlockedMessage = "macOS hasn't allowed this app to use the stack's drive."

/// Whether restarting the stack could fix what the snapshot shows. A restart is an outage, so the panel
/// only recommends one for faults the stack owns. Ollama and the tunnel run outside it.
public func restartWouldHelp(_ snapshot: HealthSnapshot) -> Bool {
    guard !snapshot.driveBlocked, snapshot.unreadable.isEmpty else { return false }
    if snapshot.containers?.allRunning == false { return true }
    if snapshot.site?.originOK == false { return true }
    return snapshot.bridges?.contains { !$0.ok && snapshot.bridgeWatch[$0.name] != .restarting } == true
}

/// The stack command seen while this app's own action runs is that action, not another session.
public func anotherSessionIsChangingStack(stackCommandRunning: Bool, ownActionRunning: Bool) -> Bool {
    stackCommandRunning && !ownActionRunning
}

public func verdict(for snapshot: HealthSnapshot, action: ActionKind?, externalBusy: Bool) -> Verdict {
    if let action {
        return Verdict(overall: action.overall, reason: "You can close this panel. The work carries on.")
    }
    if externalBusy {
        return Verdict(overall: .busy, reason: "Another session is changing the stack")
    }
    if snapshot.driveBlocked {
        return Verdict(overall: .degraded, reason: driveBlockedMessage)
    }
    guard snapshot.isComplete || !snapshot.unreadable.isEmpty else {
        return Verdict(overall: .checking, reason: "Reading the stack")
    }
    if let site = snapshot.site, let containers = snapshot.containers,
       containers.running == 0, !site.publicOK, !site.originOK {
        return Verdict(overall: .offline, reason: "Visitors can't reach the site. Your data is safe.")
    }
    let found = problems(in: snapshot)
    guard let first = found.first else {
        return Verdict(overall: .online, reason: "Everything is answering")
    }
    let reason = found.count > 1 ? "\(first), and \(found.count - 1) more" : first
    return Verdict(overall: .degraded, reason: reason)
}
