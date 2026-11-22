import Foundation
import Testing
@testable import MothershipBarCore

private let start = Date(timeIntervalSince1970: 1_000)
private let down = [BridgeReading(name: "Optimizer", ok: false), BridgeReading(name: "AI", ok: true)]
private let up = [BridgeReading(name: "Optimizer", ok: true), BridgeReading(name: "AI", ok: true)]

private func restartAfterTwoReads(_ watch: inout BridgeWatch) -> String? {
    _ = watch.observe(down, clear: true, now: start)
    return watch.observe(down, clear: true, now: start)
}

@Test func oneFailedReadRestartsNothing() {
    var watch = BridgeWatch()
    #expect(watch.observe(down, clear: true, now: start) == nil)
    #expect(watch.observe(up, clear: true, now: start) == nil)
    #expect(watch.observe(down, clear: true, now: start) == nil)
}

@Test func twoFailedReadsInARowRestartTheBridge() {
    var watch = BridgeWatch()
    #expect(restartAfterTwoReads(&watch) == "Optimizer")
    #expect(watch.statuses["Optimizer"] == .restarting)
}

@Test func nothingRestartsWhileTheStackIsBeingChanged() {
    var watch = BridgeWatch()
    for _ in 0..<5 { #expect(watch.observe(down, clear: false, now: start) == nil) }
    #expect(watch.observe(down, clear: true, now: start) == nil, "the count starts again once the stack is clear")
    #expect(watch.observe(down, clear: true, now: start) == "Optimizer")
}

@Test func onlyOneBridgeRestartsAtATime() {
    var watch = BridgeWatch()
    let bothDown = [BridgeReading(name: "Optimizer", ok: false), BridgeReading(name: "AI", ok: false)]
    _ = watch.observe(bothDown, clear: true, now: start)
    #expect(watch.observe(bothDown, clear: true, now: start) == "Optimizer")
    #expect(watch.observe(bothDown, clear: true, now: start) == nil)
    watch.finished("Optimizer", outcome: .succeeded, now: start)
    #expect(watch.observe(bothDown, clear: true, now: start) == "AI")
}

@Test func aBridgeThatAnswersAfterItsRestartIsForgotten() {
    var watch = BridgeWatch()
    _ = restartAfterTwoReads(&watch)
    watch.finished("Optimizer", outcome: .succeeded, now: start)
    #expect(watch.statuses["Optimizer"] == .restarting, "a clean exit is not proof until the bridge answers")
    _ = watch.observe(up, clear: true, now: start)
    #expect(watch.statuses.isEmpty)
}

@Test func givesUpAfterTwoRestartsThatDidNotBringTheBridgeBack() {
    var watch = BridgeWatch()
    #expect(restartAfterTwoReads(&watch) == "Optimizer")
    watch.finished("Optimizer", outcome: .succeeded, now: start)
    #expect(restartAfterTwoReads(&watch) == "Optimizer")
    watch.finished("Optimizer", outcome: .succeeded, now: start)
    #expect(restartAfterTwoReads(&watch) == nil)
    #expect(watch.statuses["Optimizer"] == .noRecovery)
    for _ in 0..<10 { #expect(watch.observe(down, clear: true, now: start) == nil) }
}

@Test func givesUpAfterTwoRestartsThatExitedWithAnError() {
    var watch = BridgeWatch()
    _ = restartAfterTwoReads(&watch)
    watch.finished("Optimizer", outcome: .failed, now: start)
    #expect(restartAfterTwoReads(&watch) == "Optimizer")
    watch.finished("Optimizer", outcome: .failed, now: start)
    #expect(watch.statuses["Optimizer"] == .noRecovery)
    #expect(restartAfterTwoReads(&watch) == nil)
}

@Test func aBridgeThatAnswersAgainIsWatchedAfresh() {
    var watch = BridgeWatch()
    _ = restartAfterTwoReads(&watch)
    watch.finished("Optimizer", outcome: .failed, now: start)
    _ = restartAfterTwoReads(&watch)
    watch.finished("Optimizer", outcome: .failed, now: start)
    _ = watch.observe(up, clear: true, now: start)
    #expect(restartAfterTwoReads(&watch) == "Optimizer")
}

@Test func aRefusedPortOrDriveStopsAtOnce() {
    for (outcome, status) in [(BridgeRestartOutcome.portHeld, BridgeWatchStatus.portHeld), (.driveRefused, .driveRefused)] {
        var watch = BridgeWatch()
        _ = restartAfterTwoReads(&watch)
        watch.finished("Optimizer", outcome: outcome, now: start)
        #expect(watch.statuses["Optimizer"] == status)
        #expect(restartAfterTwoReads(&watch) == nil)
    }
}

@Test func aHeldRestartPausesTheWatcherAndCountsAgainstNothing() {
    var watch = BridgeWatch()
    _ = restartAfterTwoReads(&watch)
    watch.finished("Optimizer", outcome: .held, now: start)
    let during = start.addingTimeInterval(BridgeWatch.heldPause - 1)
    _ = watch.observe(down, clear: true, now: during)
    #expect(watch.observe(down, clear: true, now: during) == nil)
    let after = start.addingTimeInterval(BridgeWatch.heldPause)
    #expect(watch.observe(down, clear: true, now: after) == "Optimizer")
    watch.finished("Optimizer", outcome: .held, now: after)
    #expect(watch.statuses["Optimizer"] == nil)
}

@Test func exitCodesMapToOutcomes() {
    #expect(bridgeRestartOutcome(exitCode: 0) == .succeeded)
    for code in [actionBusyExitCode, actionDeployRunningExitCode, actionStackNotRunningExitCode] {
        #expect(bridgeRestartOutcome(exitCode: code) == .held)
    }
    #expect(bridgeRestartOutcome(exitCode: actionBridgePortHeldExitCode) == .portHeld)
    #expect(bridgeRestartOutcome(exitCode: actionDriveRefusedExitCode) == .driveRefused)
    #expect(bridgeRestartOutcome(exitCode: 124) == .failed)
    #expect(bridgeRestartOutcome(exitCode: nil) == .failed)
}

@Test func healthAnswersCountOnlyTheBridgesOwnFaults() {
    func body(_ text: String) -> Data { Data(text.utf8) }
    #expect(bridgeAnswered(status: 200, body: body(#"{"ok":true,"ollama":false}"#)))
    #expect(bridgeAnswered(status: 200, body: body(#"{"ok":false,"ollama":false}"#)))
    #expect(bridgeAnswered(status: 200, body: body("not json")))
    #expect(!bridgeAnswered(status: 200, body: body(#"{"ok":false}"#)))
    #expect(!bridgeAnswered(status: 502, body: body(#"{"ok":true}"#)))
    #expect(!bridgeAnswered(status: nil, body: nil))
}

@Test func anAnsweringBridgeWhoseOllamaListsNoModelsNeedsAttentionButNoRestart() {
    func body(_ text: String) -> Data { Data(text.utf8) }
    let outage = body(#"{"ok":true,"ollama":true,"models":{"caption":false,"embed":false}}"#)
    #expect(bridgeAnswered(status: 200, body: outage), "the bridge itself is fine, so the watcher leaves it alone")
    #expect(bridgeMissingModels(body: outage) == ["caption", "embed"])
    #expect(bridgeMissingModels(body: body(#"{"ok":true,"ollama":true,"models":{"caption":true,"embed":false}}"#)) == ["embed"])
    #expect(bridgeMissingModels(body: body(#"{"ok":true,"ollama":true,"models":{"caption":true,"embed":true}}"#)).isEmpty)
    #expect(bridgeMissingModels(body: body(#"{"ok":true}"#)).isEmpty, "a bridge without model fields reads as before")
    #expect(bridgeMissingModels(body: nil).isEmpty)

    let outageReadings = [
        BridgeReading(name: "Optimizer", ok: true),
        BridgeReading(name: "AI", ok: true, missingModels: ["caption", "embed"]),
    ]
    let snapshot = HealthSnapshot(
        site: SiteReading(publicStatus: 200, publicMs: 300, originStatus: 200),
        containers: ContainerReading(running: 13, total: 13, notRunning: []),
        tunnel: TunnelReading(jobRunning: true, readyConnections: 4),
        bridges: outageReadings,
        ollama: OllamaReading(version: "0.40.16", loadedModels: 0)
    )
    let result = verdict(for: snapshot, action: nil, externalBusy: false)
    #expect(result.overall == .degraded)
    #expect(result.overall.headline == "Needs attention")
    #expect(result.reason == "The AI bridge can't find its caption and embed models in Ollama")
    #expect(!restartWouldHelp(snapshot), "Ollama runs outside the stack, so a stack restart can't fix it")
    var watch = BridgeWatch()
    for _ in 0..<3 { #expect(watch.observe(outageReadings, clear: true, now: start) == nil, "the watcher restarts nothing") }
    let one = HealthSnapshot(bridges: [BridgeReading(name: "AI", ok: true, missingModels: ["embed"])])
    #expect(problems(in: one) == ["The AI bridge can't find its embed model in Ollama"])
}

@Test func bridgeTargetsComeFromTheNamedKeys() {
    let text = """
    MOTHERSHIP_OPTIMIZER_API=http://192.168.64.1:18766
    MOTHERSHIP_AI_API=http://192.168.64.1:18767
    MOTHERSHIP_HOME_VIDEO_API=http://192.168.64.1
    OTHER_KEY=http://10.0.0.1:1
    """
    #expect(parseBridgeTargets(text) == [
        BridgeTarget(name: "Optimizer", label: "optimizer", host: "192.168.64.1", port: 18766),
        BridgeTarget(name: "AI", label: "ai", host: "192.168.64.1", port: 18767),
        BridgeTarget(name: "Video", label: "video", host: "192.168.64.1", port: 80),
    ])
}

@Test func thePanelSaysWhatTheWatcherIsDoing() {
    var snapshot = HealthSnapshot(bridges: down)
    snapshot.bridgeWatch = ["Optimizer": .restarting]
    #expect(problems(in: snapshot) == ["Restarting the Optimizer bridge"])
    #expect(healthRows(snapshot: snapshot, overall: .degraded)[3].value == "1 restarting")
    #expect(!restartWouldHelp(snapshot), "the panel doesn't suggest an outage while the watcher is on it")
    snapshot.bridgeWatch = ["Optimizer": .noRecovery]
    #expect(problems(in: snapshot) == ["The Optimizer bridge didn't come back after two restarts"])
    #expect(healthRows(snapshot: snapshot, overall: .degraded)[3].value == "Optimizer down")
    #expect(restartWouldHelp(snapshot))
}
