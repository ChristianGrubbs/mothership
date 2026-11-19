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
    // A long name such as "vectorization queue" can't fit, so the row gives the count and the line under the
    // board names every stopped container.
    let named = "\(first.replacingOccurrences(of: "_", with: " ")) down"
    let single = containers.notRunning.count == 1 && fitsTheBoard(named)
    return HealthRow("Containers", problem(single ? named : "\(count) up"))
}

/// Whether a status reads in full in the board's status cells.
func fitsTheBoard(_ status: String) -> Bool {
    status.count <= FlapBoardCells.status
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
    guard !bridges.isEmpty else { return HealthRow("Bridges", problem("Not set up")) }
    let healthy = bridges.filter(\.ok).count
    let count = "\(healthy) of \(bridges.count)"
    if healthy == bridges.count { return HealthRow("Bridges", (count, .ok)) }
    let failing = bridges.filter { !$0.ok }.map(\.name)
    let restarting = failing.filter { watch[$0] == .restarting }.count
    if restarting > 0 { return HealthRow("Bridges", problem("\(restarting) restarting")) }
    if healthy == 0 { return HealthRow("Bridges", problem("All \(bridges.count) down")) }
    // Names the bridges that are down when they fit ("Video down", "AI, Video down"). Otherwise the row gives
    // the count and the line under the board names them.
    let named = "\(failing.joined(separator: ", ")) down"
    return HealthRow("Bridges", problem(fitsTheBoard(named) ? named : "\(count) up"))
}

private func ollamaRow(_ ollama: OllamaReading?, problem: (String) -> HealthRow.Parts) -> HealthRow {
    guard let ollama else { return .checking("Ollama") }
    guard ollama.version != nil else { return HealthRow("Ollama", problem("No answer")) }
    // The board holds 14 characters, so the count of models can't carry the word "models" as well.
    return HealthRow("Ollama", ("\(ollama.loadedModels) loaded", .ok))
}
