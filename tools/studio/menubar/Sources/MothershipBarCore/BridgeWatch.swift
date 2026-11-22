import Foundation

/// Exit codes the runner uses when it refuses or cannot finish a bridge restart.
public let actionBridgePortHeldExitCode = 83
public let actionStackNotRunningExitCode = 84

/// One native bridge, as `app.env` places it.
public struct BridgeTarget: Sendable, Equatable {
    public var name: String
    public var label: String
    public var host: String
    public var port: Int
    public init(name: String, label: String, host: String, port: Int) {
        self.name = name
        self.label = label
        self.host = host
        self.port = port
    }
}

/// Where each bridge listens. Reads the same three keys as `parseBridgeEndpoints` and nothing else.
public func parseBridgeTargets(_ text: String) -> [BridgeTarget] {
    let labels = ["Optimizer": "optimizer", "AI": "ai", "Video": "video"]
    return parseBridgeEndpoints(text).compactMap { endpoint in
        guard let label = labels[endpoint.name], let host = endpoint.url.host() else { return nil }
        // The stack accepts a plain http address with no port, and reads it as port 80.
        return BridgeTarget(name: endpoint.name, label: label, host: host, port: endpoint.url.port ?? 80)
    }
}

/// Whether a bridge's `/health` answer counts as healthy. A stopped Ollama is its own fault: restarting
/// the AI bridge cannot fix it, so that answer never counts against the bridge.
public func bridgeAnswered(status: Int?, body: Data?) -> Bool {
    guard status == 200 else { return false }
    guard let body, let fields = try? JSONSerialization.jsonObject(with: body) as? [String: Any],
          let ok = fields["ok"] as? Bool, !ok else { return true }
    return fields["ollama"] as? Bool == false
}

/// The models a bridge's `/health` reports as missing (`"models": {"caption": false}`), sorted. A bridge whose
/// Ollama lists no models still answers, so it still counts as answering: restarting it cannot help, and this
/// never feeds the watcher. Empty when the answer carries no model fields.
public func bridgeMissingModels(body: Data?) -> [String] {
    guard let body, let fields = try? JSONSerialization.jsonObject(with: body) as? [String: Any],
          let models = fields["models"] as? [String: Any] else { return [] }
    return models.compactMap { name, present in (present as? Bool) == false ? name : nil }.sorted()
}

public enum BridgeRestartOutcome: String, Sendable, Equatable {
    case succeeded
    /// Something else is changing the stack, so nothing was restarted. This never counts as a failed restart.
    case held
    case failed
    /// Another process holds the bridge's port. The app never stops a process it doesn't own.
    case portHeld
    case driveRefused
}

public func bridgeRestartOutcome(exitCode: Int?) -> BridgeRestartOutcome {
    switch exitCode {
    case 0: .succeeded
    case actionBusyExitCode, actionDeployRunningExitCode, actionStackNotRunningExitCode: .held
    case actionBridgePortHeldExitCode: .portHeld
    case actionDriveRefusedExitCode: .driveRefused
    default: .failed
    }
}

/// What the watcher is doing about a bridge that isn't answering.
public enum BridgeWatchStatus: String, Sendable, Equatable {
    case restarting, noRecovery, portHeld, driveRefused

    func problem(bridge name: String) -> String {
        switch self {
        case .restarting: "Restarting the \(name) bridge"
        case .noRecovery: "The \(name) bridge didn't come back after two restarts"
        case .portHeld: "Another program is using the \(name) bridge's port"
        case .driveRefused: "macOS refused to let the app restart the \(name) bridge"
        }
    }
}

/// Decides when a stopped bridge is restarted. It acts on one bridge at a time, after two failed reads in a
/// row, and only while nothing else is changing the stack. After two failed restarts it stops trying until
/// the bridge answers again.
public struct BridgeWatch: Sendable {
    public static let failedReadsBeforeRestart = 2
    public static let failedRestartsBeforeGivingUp = 2
    /// Seconds to leave the stack alone after the runner reports that something else is changing it.
    public static let heldPause: TimeInterval = 120

    private struct Entry: Sendable {
        var failedReads = 0
        var failedRestarts = 0
        var awaitingProof = false
        var stopped: BridgeWatchStatus?
    }

    private var entries: [String: Entry] = [:]
    private var inFlight: String?
    private var pausedUntil = Date.distantPast

    public init() {}

    /// Takes one health reading of every bridge. Returns the bridge to restart now, if any.
    /// `clear` is false while a stack action, a deploy or a stopped stack rules out a restart.
    public mutating func observe(_ readings: [BridgeReading], clear: Bool, now: Date) -> String? {
        for reading in readings {
            if reading.ok {
                entries[reading.name] = nil
            } else if reading.name != inFlight {
                entries[reading.name, default: Entry()].failedReads += 1
            }
        }
        guard clear else {
            // Whatever is changing the stack may bring the bridge back, so the count starts again afterwards.
            for name in entries.keys { entries[name]?.failedReads = 0 }
            return nil
        }
        guard inFlight == nil, now >= pausedUntil else { return nil }
        for reading in readings where !reading.ok {
            guard var entry = entries[reading.name], entry.stopped == nil,
                  entry.failedReads >= Self.failedReadsBeforeRestart else { continue }
            if entry.awaitingProof {
                entry.awaitingProof = false
                entry.failedRestarts += 1
                if entry.failedRestarts >= Self.failedRestartsBeforeGivingUp {
                    entry.stopped = .noRecovery
                    entries[reading.name] = entry
                    continue
                }
            }
            entry.failedReads = 0
            entries[reading.name] = entry
            inFlight = reading.name
            return reading.name
        }
        return nil
    }

    /// Records how a restart ended. A clean exit proves nothing until the bridge answers a health read.
    public mutating func finished(_ name: String, outcome: BridgeRestartOutcome, now: Date) {
        if inFlight == name { inFlight = nil }
        var entry = entries[name] ?? Entry()
        entry.failedReads = 0
        switch outcome {
        case .succeeded:
            entry.awaitingProof = true
        case .held:
            pausedUntil = now.addingTimeInterval(Self.heldPause)
        case .failed:
            entry.failedRestarts += 1
            if entry.failedRestarts >= Self.failedRestartsBeforeGivingUp { entry.stopped = .noRecovery }
        case .portHeld:
            entry.stopped = .portHeld
        case .driveRefused:
            entry.stopped = .driveRefused
        }
        entries[name] = entry
    }

    public var statuses: [String: BridgeWatchStatus] {
        var result: [String: BridgeWatchStatus] = [:]
        for (name, entry) in entries {
            if let stopped = entry.stopped {
                result[name] = stopped
            } else if entry.awaitingProof {
                result[name] = .restarting
            }
        }
        if let inFlight { result[inFlight] = .restarting }
        return result
    }
}
