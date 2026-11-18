import Foundation

public enum RowHealth: Sendable, Equatable {
    case ok, failing, off, checking
}

public struct HealthRow: Sendable, Equatable, Identifiable {
    public var id: String { name }
    public var name: String
    public var value: String
    public var health: RowHealth
    public init(name: String, value: String, health: RowHealth) {
        self.name = name
        self.value = value
        self.health = health
    }
}

/// The five health rows. While the stack is off or changing, expected outages read as neutral, not as alarms.
public func healthRows(snapshot: HealthSnapshot, overall: Overall) -> [HealthRow] {
    let quiet = overall == .offline || overall.isChanging
    func problem(_ value: String) -> HealthRow.Parts { (value, quiet ? .off : .failing) }
    return [
        siteRow(snapshot.site, quiet: quiet, problem: problem),
        containersRow(snapshot.containers, unreadable: snapshot.unreadable.contains(.containers), problem: problem),
        tunnelRow(snapshot.tunnel, problem: problem),
        bridgesRow(
            snapshot.bridges, watch: snapshot.bridgeWatch, unreadable: snapshot.unreadable.contains(.bridges), problem: problem
        ),
        ollamaRow(snapshot.ollama, problem: problem),
    ]
}

extension HealthRow {
    typealias Parts = (value: String, health: RowHealth)
    fileprivate init(_ name: String, _ parts: Parts) { self.init(name: name, value: parts.value, health: parts.health) }
    fileprivate static func checking(_ name: String) -> HealthRow { HealthRow(name: name, value: "Checking", health: .checking) }
}

private func siteRow(_ site: SiteReading?, quiet: Bool, problem: (String) -> HealthRow.Parts) -> HealthRow {
    guard let site else { return .checking("Site") }
    if site.publicOK { return HealthRow("Site", ("Answering", .ok)) }
    if quiet { return HealthRow("Site", ("Off", .off)) }
    if let status = site.publicStatus { return HealthRow("Site", problem("Error \(status)")) }
    return HealthRow("Site", problem("No answer"))
}

private func containersRow(
    _ containers: ContainerReading?, unreadable: Bool, problem: (String) -> HealthRow.Parts
) -> HealthRow {
    guard let containers else {
        return unreadable ? HealthRow("Containers", problem("Can't read")) : .checking("Containers")
    }
    let count = "\(containers.running) of \(containers.total)"
    if containers.allRunning { return HealthRow("Containers", (count, .ok)) }
    guard let first = containers.notRunning.first, containers.running > 0 else {
        return HealthRow("Containers", problem(count))
    }
    guard containers.notRunning.count == 1 else { return HealthRow("Containers", problem("\(count) running")) }
    return HealthRow("Containers", problem("\(first.replacingOccurrences(of: "_", with: " ")) down"))
}

private func tunnelRow(_ tunnel: TunnelReading?, problem: (String) -> HealthRow.Parts) -> HealthRow {
    guard let tunnel else { return .checking("Tunnel") }
    if tunnel.connected { return HealthRow("Tunnel", ("Connected", .ok)) }
    return HealthRow("Tunnel", problem(tunnel.jobRunning ? "Not connected" : "Not running"))
}

private func bridgesRow(
    _ bridges: [BridgeReading]?, watch: [String: BridgeWatchStatus], unreadable: Bool, problem: (String) -> HealthRow.Parts
) -> HealthRow {
    guard let bridges else {
        return unreadable ? HealthRow("Bridges", problem("Can't read")) : .checking("Bridges")
    }
    guard !bridges.isEmpty else { return HealthRow("Bridges", problem("None configured")) }
    let healthy = bridges.filter(\.ok).count
    let count = "\(healthy) of \(bridges.count)"
    if healthy == bridges.count { return HealthRow("Bridges", (count, .ok)) }
    let failing = bridges.filter { !$0.ok }.map(\.name)
    // The board holds 14 characters. The fault line under it names the bridge.
    let restarting = failing.filter { watch[$0] == .restarting }.count
    if restarting > 0 { return HealthRow("Bridges", problem("\(restarting) restarting")) }
    if healthy == 0 { return HealthRow("Bridges", problem("All \(bridges.count) down")) }
    return HealthRow("Bridges", problem("\(count), \(failing.joined(separator: ", ")) down"))
}

private func ollamaRow(_ ollama: OllamaReading?, problem: (String) -> HealthRow.Parts) -> HealthRow {
    guard let ollama else { return .checking("Ollama") }
    guard ollama.version != nil else { return HealthRow("Ollama", problem("No answer")) }
    let models = ollama.loadedModels == 1 ? "1 model loaded" : "\(ollama.loadedModels) models loaded"
    return HealthRow("Ollama", (models, .ok))
}
