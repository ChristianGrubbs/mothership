import Foundation
import MothershipBarCore

/// Host-specific settings. The installer writes this file; nothing host-specific lives in the source tree.
struct BarConfig: Codable, Sendable {
    var wrapperPath: String
    var appEnvPath: String
    var siteURL: String
    var originURL: String
    var containerBinary: String
    var tunnelLabel: String
    var tunnelReadyURL: String
    var ollamaURL: String
    var diskPath: String
    var containerPrefix: String
    var uptimeService: String
    var umbrelStore: UmbrelStoreSource?
    /// Where the web app reads the Umbrel's free space. Nil leaves the file unwritten.
    var umbrelCapacityFile: String?
    var craftTool: CraftToolConfig?
    var deployGuard: DeployGuardConfig?
    /// How to restart a stopped bridge. Nil leaves the watcher off.
    var bridgeWatch: BridgeWatchConfig?
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
