import Foundation
import Testing
@testable import MothershipBarCore

/// The shape `linux-host-status.py` printed against the Windows PC's VM on 2026-11-24, with the healthy AI
/// bridge's real answer and one container stopped.
private let report = #"""
{"containers":[{"name":"ai_index_queue","running":false},{"name":"fpm","running":true},{"name":"nginx","running":true}],
"tunnel":{"running":true,"readyConnections":4},
"bridges":[{"name":"AI","status":200,"body":"{\"ok\": true, \"version\": \"1\", \"ollama\": true, \"models\": {\"caption\": true, \"embed\": false}}"},
{"name":"Video","status":null,"body":null}],
"ollama":{"version":"0.40.16","loaded":0},"originStatus":200,"busy":true,
"stats":{"memoryBytes":1920288680,"uptimeSeconds":26524,"diskFreeBytes":186773618688}}
"""#

@Test func linuxHostReportBecomesTheBoardsReadings() throws {
    let reading = try #require(parseLinuxHostReport(Data(report.utf8)))
    #expect(reading.containers == ContainerReading(running: 2, total: 3, notRunning: ["ai_index_queue"]))
    #expect(reading.tunnel == TunnelReading(jobRunning: true, readyConnections: 4))
    #expect(reading.bridges == [
        BridgeReading(name: "AI", ok: true, missingModels: ["embed"]),
        BridgeReading(name: "Video", ok: false),
    ])
    #expect(reading.ollama == OllamaReading(version: "0.40.16", loadedModels: 0))
    #expect(reading.originStatus == 200)
    #expect(reading.busy)
    #expect(reading.stats == StatsReading(memoryBytes: 1_920_288_680, uptimeSeconds: 26_524, diskFreeBytes: 186_773_618_688))
}

@Test func linuxHostPartsTheHostCouldNotReadStayUnknown() throws {
    let text = #"{"containers":null,"tunnel":{"running":false,"readyConnections":null},"bridges":null,"#
        + #""ollama":{"version":null,"loaded":0},"originStatus":null,"busy":false,"stats":null}"#
    let reading = try #require(parseLinuxHostReport(Data(text.utf8)))
    #expect(reading.containers == nil)
    #expect(reading.bridges == nil)
    #expect(reading.stats == nil)
    #expect(!reading.tunnel.connected)
}

@Test func cutOffLinuxHostReportCountsAsAFailedRead() {
    #expect(parseLinuxHostReport(Data(report.prefix(80).utf8)) == nil)
    #expect(parseLinuxHostReport(Data()) == nil)
}

private let source = LinuxHostSource(
    sshTarget: "mothership@vm.example", jumpHost: "jump-host", keyPath: "/Users/me/.ssh/key",
    knownHostsPath: "/Users/me/Library/Application Support/The Mothership Menu Bar/linux-host-known-hosts",
    home: "/srv/mothership", displayName: "Windows PC"
)

@Test func linuxHostSSHPinsTheHostKeyAndRunsOnlyTheStatusScript() throws {
    let arguments = try #require(source.sshArguments(includeStats: true))
    #expect(arguments.contains("StrictHostKeyChecking=yes"))
    #expect(arguments.contains("BatchMode=yes"))
    #expect(arguments.contains("UserKnownHostsFile=\"\(source.knownHostsPath)\""))
    #expect(Array(arguments.suffix(7)) == [
        "-T", "mothership@vm.example", "python3", "-", "--home", "/srv/mothership", "--stats",
    ])
    let jump = try #require(arguments.firstIndex(of: "-J"))
    #expect(arguments[jump + 1] == "jump-host")
    #expect(source.sshArguments(includeStats: false)?.last == "/srv/mothership")
}

@Test func linuxHostValuesThatCouldReachTheRemoteShellAreRefused() {
    var unsafe = source
    unsafe.home = "/srv/mothership; reboot"
    #expect(unsafe.sshArguments(includeStats: false) == nil)
    unsafe = source
    unsafe.sshTarget = "mothership@host -oProxyCommand=x"
    #expect(unsafe.sshArguments(includeStats: false) == nil)
    unsafe = source
    unsafe.jumpHost = "jump-host -oProxyCommand=x"
    #expect(unsafe.sshArguments(includeStats: false) == nil)
    unsafe = source
    unsafe.knownHostsPath = "relative/known_hosts"
    #expect(unsafe.sshArguments(includeStats: false) == nil)
}

@Test func unreachableHostIsOneProblemAndEveryHostRowSaysSo() {
    let snapshot = HealthSnapshot(
        site: SiteReading(publicStatus: 200, publicMs: 300, originStatus: nil), unreadable: [.host]
    )
    let result = verdict(for: snapshot, action: nil, externalBusy: false)
    #expect(result.overall == .degraded)
    #expect(result.reason == "Can't reach the server that runs the stack")
    let rows = healthRows(snapshot: snapshot, overall: result.overall)
    #expect(rows.map(\.name) == ["Site", "Containers", "Tunnel", "Bridges", "Ollama"])
    #expect(rows[0] == HealthRow(name: "Site", value: "Answering", health: .ok))
    #expect(rows.dropFirst().allSatisfy { $0.value == "Can't read" && $0.health == .failing })
    #expect(!restartWouldHelp(snapshot))
}
