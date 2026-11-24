import Foundation
import MothershipBarCore

/// Host-specific settings. The installer writes this file; nothing host-specific lives in the source tree.
/// With `linuxHost` set the app reads the stack on that host and changes nothing. The fields marked "this Mac"
/// are then unset, and the app offers no Start, Stop or Restart.
struct BarConfig: Codable, Sendable {
    var siteURL: String
    var linuxHost: LinuxHostSource?
    /// This Mac's stack wrapper.
    var wrapperPath: String?
    /// This Mac's stack settings.
    var appEnvPath: String?
    /// This Mac's origin.
    var originURL: String?
    /// This Mac's container runtime.
    var containerBinary: String?
    /// This Mac's tunnel job.
    var tunnelLabel: String?
    /// This Mac's tunnel.
    var tunnelReadyURL: String?
    /// This Mac's Ollama.
    var ollamaURL: String?
    /// The volume that holds this Mac's stack.
    var diskPath: String?
    /// This Mac's container names.
    var containerPrefix: String?
    /// This Mac's container whose age is the stack's uptime.
    var uptimeService: String?
    var umbrelStore: UmbrelStoreSource?
    /// Where the web app reads the Umbrel's free space. Nil leaves the file unwritten.
    var umbrelCapacityFile: String?
    var craftTool: CraftToolConfig?
    var deployGuard: DeployGuardConfig?
    /// How to restart a stopped bridge. Nil leaves the watcher off.
    var bridgeWatch: BridgeWatchConfig?

    /// Whether this app may start, stop and restart the stack: only a stack on this Mac.
    var controlsStack: Bool { linuxHost == nil && wrapperPath != nil }
}

/// What each bridge's own manager needs. The app only ever runs that manager's `up`.
struct BridgeWatchConfig: Codable, Sendable {
    var python: String
    /// The deployed checkout that holds the managers.
    var source: String
    /// The folder that holds each bridge's installed state.
    var nativeServices: String
}

/// The ArtCraft update tool. It owns every change to the apps, the engines and the server.
struct CraftToolConfig: Codable, Sendable {
    var python: String
    var script: String
}

/// How the app sees a deploy in progress. Every action that changes the stack refuses to run without it.
struct DeployGuardConfig: Codable, Sendable {
    /// Reads who holds the deploy checkout.
    var coordinator: String
    /// The lease a deploy holds, such as `worktree:/path/to/live`.
    var lease: String
}

/// Where the app keeps state. `MOTHERSHIP_BAR_HOME` isolates test runs from the installed app.
enum BarPaths {
    static let root: URL = {
        if let override = ProcessInfo.processInfo.environment["MOTHERSHIP_BAR_HOME"] {
            return URL(fileURLWithPath: override, isDirectory: true)
        }
        return FileManager.default.homeDirectoryForCurrentUser
            .appending(path: "Library/Application Support/The Mothership Menu Bar", directoryHint: .isDirectory)
    }()
    static let config = root.appending(path: "config.json")
    static let actions = root.appending(path: "actions", directoryHint: .isDirectory)
    static let appLog = root.appending(path: "app.jsonl")
    static let loginMarker = root.appending(path: "login-session")
    static let preferences = root.appending(path: "start-at-login")
    static let craftStatus = root.appending(path: "craft-status.json")
    /// When the stack's drive last dropped off while the stack ran. Present only until the app has dealt with it.
    static let driveDrop = root.appending(path: "drive-dropped")
}

/// Structured log for later diagnosis. Holds event names and small typed values, never command output.
enum BarLog {
    private static let runID = UUID().uuidString.lowercased()
    private static let queue = DispatchQueue(label: "app.themothership.menubar.log")
    private static let verbose = ProcessInfo.processInfo.environment["MOTHERSHIP_BAR_DEBUG"] == "1"

    static func write(_ level: String, _ event: String, loc: String, ctx: [String: String] = [:]) {
        if level == "debug", !verbose { return }
        let record: [String: Any] = [
            "ts": ISO8601DateFormatter().string(from: Date()), "run_id": runID, "level": level,
            "event": event, "loc": loc, "ctx": ctx,
        ]
        guard var data = try? JSONSerialization.data(withJSONObject: record, options: [.sortedKeys]) else { return }
        data.append(0x0A)
        queue.async {
            try? FileManager.default.createDirectory(at: BarPaths.root, withIntermediateDirectories: true)
            if let handle = try? FileHandle(forWritingTo: BarPaths.appLog) {
                _ = try? handle.seekToEnd()
                try? handle.write(contentsOf: data)
                try? handle.close()
            } else {
                try? data.write(to: BarPaths.appLog, options: .atomic)
            }
        }
    }
}
