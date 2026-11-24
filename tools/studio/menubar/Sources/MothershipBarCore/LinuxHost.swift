import Foundation

/// The production stack on a Linux host, reached over SSH. The installer writes these values. The app only
/// reads that host: it runs the read-only `linux-host-status.py` there and never starts, stops or deploys.
public struct LinuxHostSource: Codable, Sendable, Equatable {
    /// `user@host` of the Linux host, as the jump host resolves it.
    public var sshTarget: String
    /// A host alias from the user's SSH config, such as the Windows PC that runs the VM. Nil connects directly.
    public var jumpHost: String?
    public var keyPath: String
    /// The app's own known-hosts file, which the installer fills. The Linux host's key must already be in it.
    public var knownHostsPath: String
    /// The folder that holds the stack's `source/` and `state/`.
    public var home: String
    /// What the panel calls the machine, such as "Windows PC".
    public var displayName: String

    public init(
        sshTarget: String, jumpHost: String?, keyPath: String, knownHostsPath: String, home: String, displayName: String
    ) {
        self.sshTarget = sshTarget
        self.jumpHost = jumpHost
        self.keyPath = keyPath
        self.knownHostsPath = knownHostsPath
        self.home = home
        self.displayName = displayName
    }

    private static func matches(_ value: String, _ pattern: String) -> Bool {
        value.range(of: pattern, options: .regularExpression) != nil
    }

    /// Arguments for `/usr/bin/ssh` with the status script on standard input, or nil when a configured value
    /// isn't safe to place in the command. SSH joins the remote words into one shell line, so each is checked.
    public func sshArguments(includeStats: Bool) -> [String]? {
        guard Self.matches(sshTarget, #"^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$"#),
              jumpHost.map({ Self.matches($0, #"^[A-Za-z0-9._@-]+$"#) }) ?? true,
              keyPath.hasPrefix("/"),
              knownHostsPath.hasPrefix("/"), !knownHostsPath.contains("\""),
              Self.matches(home, #"^/[A-Za-z0-9._/-]*$"#) else { return nil }
        var arguments = [
            "-i", keyPath, "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "ConnectTimeout=10",
            "-o", "StrictHostKeyChecking=yes", "-o", "UserKnownHostsFile=\"\(knownHostsPath)\"", "-o", "LogLevel=ERROR",
        ]
        if let jumpHost { arguments += ["-J", jumpHost] }
        arguments += ["-T", sshTarget, "python3", "-", "--home", home]
        if includeStats { arguments.append("--stats") }
        return arguments
    }
}

/// One read of the Linux host. A nil part could not be read there.
public struct LinuxHostReading: Sendable, Equatable {
    public var containers: ContainerReading?
    public var tunnel: TunnelReading
    public var bridges: [BridgeReading]?
    public var ollama: OllamaReading
    public var originStatus: Int?
    /// A deploy or another session is running the stack's `up` or `stop` on the host.
    public var busy: Bool
    /// Present only when the read asked for it.
    public var stats: StatsReading?

    public init(
        containers: ContainerReading?, tunnel: TunnelReading, bridges: [BridgeReading]?, ollama: OllamaReading,
        originStatus: Int?, busy: Bool, stats: StatsReading?
    ) {
        self.containers = containers
        self.tunnel = tunnel
        self.bridges = bridges
        self.ollama = ollama
        self.originStatus = originStatus
        self.busy = busy
        self.stats = stats
    }
}

private struct LinuxHostReport: Decodable {
    struct Container: Decodable {
        var name: String
        var running: Bool
    }

    struct Tunnel: Decodable {
        var running: Bool
        var readyConnections: Int?
    }

    struct Bridge: Decodable {
        var name: String
        var status: Int?
        var body: String?
    }

    struct Ollama: Decodable {
        var version: String?
        var loaded: Int?
    }

    struct Stats: Decodable {
        var memoryBytes: UInt64?
        var uptimeSeconds: Int?
        var diskFreeBytes: Int64?
    }

    var containers: [Container]?
    var tunnel: Tunnel
    var bridges: [Bridge]?
    var ollama: Ollama
    var originStatus: Int?
    var busy: Bool
    var stats: Stats?
}

/// Reads the status script's JSON. Nil when the output isn't a whole report, so a cut-off read counts as failed.
public func parseLinuxHostReport(_ data: Data) -> LinuxHostReading? {
    guard let report = try? JSONDecoder().decode(LinuxHostReport.self, from: data) else { return nil }
    let containers = report.containers.map { rows in
        ContainerReading(
            running: rows.filter(\.running).count, total: rows.count, notRunning: rows.filter { !$0.running }.map(\.name)
        )
    }
    let bridges = report.bridges?.map { bridge in
        let body = bridge.body.map { Data($0.utf8) }
        return BridgeReading(
            name: bridge.name, ok: bridgeAnswered(status: bridge.status, body: body), missingModels: bridgeMissingModels(body: body)
        )
    }
    return LinuxHostReading(
        containers: containers,
        tunnel: TunnelReading(jobRunning: report.tunnel.running, readyConnections: report.tunnel.readyConnections),
        bridges: bridges,
        ollama: OllamaReading(version: report.ollama.version, loadedModels: report.ollama.loaded ?? 0),
        originStatus: report.originStatus,
        busy: report.busy,
        stats: report.stats.map {
            StatsReading(memoryBytes: $0.memoryBytes, uptimeSeconds: $0.uptimeSeconds, diskFreeBytes: $0.diskFreeBytes)
        }
    )
}
