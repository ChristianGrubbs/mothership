import Foundation
import Testing
@testable import MothershipBarCore

private let healthy = HealthSnapshot(
    site: SiteReading(publicStatus: 200, publicMs: 300, originStatus: 200),
    containers: ContainerReading(running: 13, total: 13, notRunning: []),
    tunnel: TunnelReading(jobRunning: true, readyConnections: 4),
    bridges: [BridgeReading(name: "AI", ok: true)],
    ollama: OllamaReading(version: "0.40.16", loadedModels: 1)
)

@Test func healthyStackIsOnline() {
    #expect(verdict(for: healthy, action: nil, externalBusy: false).overall == .online)
}

@Test func stoppedStackIsOfflineEvenWithTunnelAndBridgesUp() {
    var snapshot = healthy
    snapshot.site = SiteReading(publicStatus: 502, publicMs: 80, originStatus: nil)
    snapshot.containers = ContainerReading(running: 0, total: 13, notRunning: [])
    #expect(verdict(for: snapshot, action: nil, externalBusy: false).overall == .offline)
}

@Test func partialStackNeedsAttentionAndNamesTheProblem() {
    var snapshot = healthy
    snapshot.containers = ContainerReading(running: 12, total: 13, notRunning: ["queue"])
    let result = verdict(for: snapshot, action: nil, externalBusy: false)
    #expect(result.overall == .degraded)
    #expect(result.reason == "12 of 13 containers running")
}

@Test func siteUpThroughOriginOnlyBlamesTheTunnel() {
    var snapshot = healthy
    snapshot.site = SiteReading(publicStatus: 530, publicMs: 90, originStatus: 200)
    #expect(problems(in: snapshot).first == "The site answers here but not through the tunnel")
}

@Test func panelFaultsKeepTheDiagnosisAndNameEveryStoppedContainer() {
    var snapshot = healthy
    snapshot.site = SiteReading(publicStatus: 530, publicMs: 90, originStatus: 200)
    snapshot.containers = ContainerReading(running: 11, total: 13, notRunning: ["vectorization_queue", "nginx"])
    #expect(detailedProblems(in: snapshot) == [
        "The site answers here but not through the tunnel",
        "11 of 13 containers running. Stopped: vectorization queue, nginx",
    ])
}

@Test func runningActionOutranksHealth() {
    #expect(verdict(for: healthy, action: .restart, externalBusy: false).overall == .restarting)
    #expect(verdict(for: healthy, action: nil, externalBusy: true).overall == .busy)
}

@Test func incompleteReadingIsCheckingNotOffline() {
    let snapshot = HealthSnapshot(site: SiteReading(publicStatus: nil, publicMs: nil, originStatus: nil))
    #expect(verdict(for: snapshot, action: nil, externalBusy: false).overall == .checking)
}

@Test func wrapperStatusIgnoresRelayAndCollectorLines() {
    let text = """
    web_transport: native-publish
    web_relay: stopped
    postgres: running 192.0.2.15
    queue: stopped unassigned
    nginx: missing
    collector: pid=46446 running=True owned=True
    """
    let reading = parseWrapperStatus(text)
    #expect(reading.running == 1)
    #expect(reading.total == 3)
    #expect(reading.notRunning == ["queue", "nginx"])
}

@Test func elapsedParsesEveryPsShape() {
    #expect(parseElapsed("  05:09\n") == 309)
    #expect(parseElapsed("02:05:09") == 7509)
    #expect(parseElapsed("3-02:05:09") == 266_709)
    #expect(parseElapsed("") == nil)
}

@Test func bridgeParserReadsOnlyTheNamedKeys() {
    let text = """
    # comment
    DB_PASSWORD=do-not-read
    export MOTHERSHIP_AI_API="http://192.0.2.1:18767"
    MOTHERSHIP_OPTIMIZER_API=http://192.0.2.1:18766
    MOTHERSHIP_HOME_VIDEO_API=
    """
    let endpoints = parseBridgeEndpoints(text)
    #expect(endpoints.map(\.name) == ["Optimizer", "AI"])
    #expect(endpoints[1].url.absoluteString == "http://192.0.2.1:18767/health")
}

@Test func bridgeParserAcceptsSpacesAroundTheKey() {
    let endpoints = parseBridgeEndpoints("MOTHERSHIP_AI_API = http://192.0.2.1:18767")
    #expect(endpoints.map(\.name) == ["AI"])
}

@Test func figuresStayShortAtEveryBoundary() {
    #expect(Format.milliseconds(999) == "999 ms")
    #expect(Format.milliseconds(1000) == "1.0 s")
    #expect(Format.uptime(86_399) == "23h 59m")
    #expect(Format.uptime(86_400) == "1d 0h")
    #expect(Format.bytes(99_900_000_000) == "99.9 GB")
    #expect(Format.bytes(100_000_000_000) == "100 GB")
    #expect(Format.bytes(999_400_000_000) == "999 GB")
    #expect(Format.bytes(999_600_000_000) == "1.0 TB")
    #expect(Format.bytes(190_700_000) == "190.7 MB")
    #expect(Format.bytes(999_940_000) == "999.9 MB")
    #expect(Format.bytes(999_960_000) == "1.0 GB")
    #expect(Format.bytes(4_200_000) == "4.2 MB")
}

private let umbrelSource = UmbrelStoreSource(
    sshTarget: "reader@192.0.2.20", keyPath: "/keys/reader", dataPath: "/data", container: "store-s3", bucket: "library"
)

@Test func umbrelReadingCarriesDriveAndLibrary() {
    let text = "7948830552064 2474118918144 5474694856704\nSize:            190.7 MB (181.9 MiB)\n"
    #expect(parseUmbrelStore(text) == UmbrelStoreReading(
        driveTotalBytes: 7_948_830_552_064, driveFreeBytes: 5_474_694_856_704, libraryBytes: 190_700_000
    ))
}

@Test func umbrelDriveStillReadsWhenTheStorageServiceIsDown() {
    let reading = parseUmbrelStore("7948830552064 2474118918144 5474694856704\n")
    #expect(reading?.driveFreeBytes == 5_474_694_856_704)
    #expect(reading?.libraryBytes == nil)
}

@Test func umbrelOutputWithoutADriveLineIsNoReading() {
    #expect(parseUmbrelStore("") == nil)
    #expect(parseUmbrelStore("Size: 190.7 MB (181.9 MiB)\n") == nil)
    #expect(parseUmbrelStore("ssh: connect to host port 22: Connection refused\n") == nil)
    #expect(parseUmbrelStore("100 50 900\n") == nil)
}

@Test func umbrelCommandReturnsOnlyTheBucketSizeLine() throws {
    let command = try #require(umbrelSource.remoteCommand)
    #expect(command.hasSuffix("| grep -E '^Size:'"))
    #expect(try #require(umbrelSource.sshArguments).contains("BatchMode=yes"))
}

@Test func unsafeUmbrelSettingsAreNeverRun() {
    var source = umbrelSource
    source.bucket = "library; rm -rf /"
    #expect(source.sshArguments == nil)
    source = umbrelSource
    source.dataPath = "/data $(id)"
    #expect(source.sshArguments == nil)
    source = umbrelSource
    source.container = "-store"
    #expect(source.sshArguments == nil)
    source = umbrelSource
    source.sshTarget = "-oProxyCommand=id@host"
    #expect(source.sshArguments == nil)
}

@Test func storeReadsAreSpacedOutAndRetriedSoonerAfterAFailure() {
    let start = Date(timeIntervalSince1970: 1000)
    #expect(storeReadIsDue(lastRead: nil, lastSucceeded: true, panelIsOpen: false, now: start))
    let minute = start.addingTimeInterval(storeReadIntervalWhileOpen)
    #expect(!storeReadIsDue(lastRead: start, lastSucceeded: true, panelIsOpen: false, now: minute))
    #expect(storeReadIsDue(lastRead: start, lastSucceeded: true, panelIsOpen: true, now: minute))
    #expect(storeReadIsDue(lastRead: start, lastSucceeded: false, panelIsOpen: false, now: minute))
    #expect(storeReadIsDue(lastRead: start, lastSucceeded: true, panelIsOpen: false, now: start.addingTimeInterval(storeReadInterval)))
}

private let craftCheckOutput = """
checking 13 apps
{"schema":1,"checked_at":"2026-11-18T17:00:00Z","apps":[\
{"app":"designcraft","name":"DesignCraft","desktop":"0.12.0","engine":"0.12.0","latest":"0.20.1","desktop_update":true,"engine_update":true,"desktop_running":true},\
{"app":"lightcraft","name":"LightCraft","desktop":"0.21.1","engine":"0.20.0","latest":"0.21.1","desktop_update":false,"engine_update":true,"desktop_running":false},\
{"app":"wordcraft","name":"WordCraft","desktop":"0.18.1","engine":"0.18.1","latest":null,"desktop_update":false,"engine_update":false,"desktop_running":false}],\
"mcp":{"servers":35,"stale":2,"open_sessions":1,"key_render":false}}

"""

@Test func craftStatusCountsEachKindOfUpdate() throws {
    let status = try #require(parseCraftStatus(craftCheckOutput))
    #expect(status.appsToUpdate == 2)
    #expect(status.desktopUpdates == 1)
    #expect(status.engineUpdates == 2)
    #expect(status.openAppsSkipped == 1)
    #expect(status.apps[2].latest == nil)
    #expect(status.mcp.isInUse)
    #expect(craftRowValue(status: status, isChecking: false, checkFailed: false) == "2 to update")
}

@Test func craftOutputThatIsNotTheResultIsIgnored() {
    #expect(parseCraftStatus("") == nil)
    #expect(parseCraftStatus("Traceback (most recent call last):\n  boom\n") == nil)
    #expect(parseCraftStatus(#"{"schema":2,"checked_at":"x","apps":[],"mcp":{"servers":0,"stale":0,"open_sessions":0,"key_render":false}}"#) == nil)
}

@Test func craftRowSaysWhyThereIsNoFigure() {
    #expect(craftRowValue(status: nil, isChecking: true, checkFailed: false) == "Checking")
    #expect(craftRowValue(status: nil, isChecking: false, checkFailed: true) == "Can't check")
}

@Test func craftChecksStayRareAndFailuresWaitBeforeRetrying() {
    let now = Date(timeIntervalSince1970: 100_000)
    #expect(craftCheckIsDue(checkedAt: nil, lastAttempt: nil, pageIsOpen: false, now: now))
    #expect(!craftCheckIsDue(checkedAt: nil, lastAttempt: now.addingTimeInterval(-60), pageIsOpen: true, now: now))
    let twoHours = now.addingTimeInterval(-7200)
    #expect(!craftCheckIsDue(checkedAt: twoHours, lastAttempt: nil, pageIsOpen: false, now: now))
    #expect(craftCheckIsDue(checkedAt: twoHours, lastAttempt: nil, pageIsOpen: true, now: now))
}

@Test func confirmationsNameWhatWillBeInterrupted() {
    let engines = confirmCopy(for: .craftEngines, mcp: nil)
    #expect(engines.message.contains("about 8 to 12 minutes"))
    #expect(engines.message.contains("Build time has not been measured"))
    #expect(engines.isDestructive)
    let busy = CraftMCPStatus(servers: 35, stale: 0, openSessions: 2, keyRender: true)
    #expect(confirmCopy(for: .craftMCPForce, mcp: busy).message.hasPrefix("2 ArtCraft sessions are open and a key render is running."))
    let one = CraftMCPStatus(servers: 35, stale: 0, openSessions: 1, keyRender: false)
    #expect(confirmCopy(for: .craftMCPForce, mcp: one).message.hasPrefix("1 ArtCraft session is open."))
}

@Test func craftFailuresSayWhatHappenedToTheSite() {
    func message(_ step: String, exit: Int = 1) -> String {
        failureMessage(
            progress: ActionProgress(step: .build, finished: true, exitCode: exit, failedStep: step), lastLogLine: nil
        )
    }
    #expect(message("prepare").contains("The site was not stopped"))
    #expect(message("build").contains("The site was started again"))
    #expect(message("mcp", exit: craftServerInUseExitCode) == "ArtCraft is in use, so its server kept running.")
    #expect(message("mcp") == "The ArtCraft server didn't restart.")
}

@Test func updatingArtCraftReadsAsAChangeInProgress() {
    #expect(verdict(for: healthy, action: .craftEngines, externalBusy: false).overall == .updating)
    #expect(Overall.updating.isChanging)
    #expect(ActionKind(rawValue: "craft-mcp-force") == .craftMCPForce)
}

@Test func anUnreachableUmbrelNeverChangesTheSiteVerdict() {
    var snapshot = healthy
    snapshot.store = .unreachable
    #expect(verdict(for: snapshot, action: nil, externalBusy: false).overall == .online)
}

@Test func commandWritingWithoutPauseIsBoundedAndCapped() async {
    let started = Date()
    let result = await runCommand("/bin/sh", ["-c", "trap '' TERM; yes"], timeout: 0.5)
    #expect(result.timedOut)
    #expect(result.output.count <= maxRetainedOutput)
    #expect(Date().timeIntervalSince(started) < 6)
}

@Test func onlyKnownLogMessagesReachThePanel() {
    #expect(displayableLogLine("  fpm did not become ready within 300 seconds\n") == "fpm did not become ready within 300 seconds")
    #expect(displayableLogLine("container runtime not ready after 600 seconds") == "container runtime not ready after 600 seconds")
    #expect(displayableLogLine("web relay did not become ready in 10s: DB_PASS=abc123") == "web relay did not become ready in 10s")
    #expect(displayableLogLine("runtime failed: postgres peer route did not become ready in 90s: refused") == "postgres peer route did not become ready in 90s")
    let unsafe = [
        "DB_PASSWORD=abc123 did not become ready in 10s: rejected",
        "DB_PASSWORD=fpm did not become ready in 10s",
        "sk-live-4f9a8b7c6d5e did not become ready within 300 seconds",
        "DB_PASS=abc123 rejected",
        "nginx: failed to start",
        "abc123 did not become ready within 300 seconds because DB_PASS=abc123",
        "failed: DB_PASSWORD=abc123 rejected",
        #"DB_PASSWORD="two words" rejected"#,
        #"{"password":"abc"}"#,
        "Authorization: Bearer abc",
        "connect postgres://app:hunter2@db/app failed",
        "value 9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b4c3d2e1f0a",
    ]
    for line in unsafe { #expect(displayableLogLine(line) == nil) }
}

@Test func failedProbeKeepsItsReadingBrieflyThenExpires() {
    var held = HeldReading<Int>()
    let start = Date(timeIntervalSince1970: 1000)
    #expect(held.update(nil, at: start).unreadable)
    #expect(held.update(7, at: start).value == 7)
    // The first failure comes long after the last good read, as it does on the five-minute cadence.
    let firstMiss = start.addingTimeInterval(processReadIntervalQuiet)
    let brief = held.update(nil, at: firstMiss)
    #expect(brief.value == 7 && !brief.unreadable)
    let stillHeld = held.update(nil, at: firstMiss.addingTimeInterval(heldReadingMaxAge))
    #expect(stillHeld.value == 7 && !stillHeld.unreadable)
    let expired = held.update(nil, at: firstMiss.addingTimeInterval(heldReadingMaxAge + 1))
    #expect(expired.value == nil && expired.unreadable)
}

@Test func goodReadingRestartsTheFailureClock() {
    var held = HeldReading<Int>()
    let start = Date(timeIntervalSince1970: 1000)
    _ = held.update(7, at: start)
    _ = held.update(nil, at: start.addingTimeInterval(60))
    _ = held.update(8, at: start.addingTimeInterval(120))
    let miss = held.update(nil, at: start.addingTimeInterval(240))
    #expect(miss.value == 8 && !miss.unreadable)
}

private func processCheck(
    lastRead: Date?, lastSucceeded: Bool = true, looksHealthy: Bool = true, siteChanged: Bool = false,
    panelIsOpen: Bool = false, actionRunning: Bool = false
) -> ProcessReadCheck {
    ProcessReadCheck(
        lastRead: lastRead, lastSucceeded: lastSucceeded, looksHealthy: looksHealthy, siteChanged: siteChanged,
        panelIsOpen: panelIsOpen, actionRunning: actionRunning
    )
}

@Test func quietHealthyPollsLaunchNoProcessForFiveMinutes() {
    let now = Date(timeIntervalSince1970: 10_000)
    #expect(healthPollInterval >= 30)
    #expect(processReadsAreDue(processCheck(lastRead: nil), now: now))
    #expect(!processReadsAreDue(processCheck(lastRead: now.addingTimeInterval(-299)), now: now))
    #expect(processReadsAreDue(processCheck(lastRead: now.addingTimeInterval(-300)), now: now))
}

@Test func processReadsComeSoonerWhenSomeoneNeedsThem() {
    let now = Date(timeIntervalSince1970: 10_000)
    let recent = now.addingTimeInterval(-60)
    #expect(processReadsAreDue(processCheck(lastRead: recent, panelIsOpen: true), now: now))
    #expect(processReadsAreDue(processCheck(lastRead: recent, looksHealthy: false), now: now))
    #expect(processReadsAreDue(processCheck(lastRead: recent, lastSucceeded: false), now: now))
    let justRead = now.addingTimeInterval(-30)
    #expect(!processReadsAreDue(processCheck(lastRead: justRead, panelIsOpen: true), now: now))
    #expect(processReadsAreDue(processCheck(lastRead: justRead, siteChanged: true), now: now))
    #expect(processReadsAreDue(processCheck(lastRead: justRead, actionRunning: true), now: now))
}

@Test func capacityFileMatchesTheWebAppContract() throws {
    let reading = UmbrelStoreReading(driveTotalBytes: 7_948_830_552_064, driveFreeBytes: 5_474_694_856_704, libraryBytes: nil)
    let data = umbrelCapacityJSON(reading, checkedAt: Date(timeIntervalSince1970: 1_795_024_860))
    let body = try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
    #expect(Set(body.keys) == ["free_bytes", "total_bytes", "checked_at"])
    #expect((body["free_bytes"] as? NSNumber)?.int64Value == 5_474_694_856_704)
    #expect((body["total_bytes"] as? NSNumber)?.int64Value == 7_948_830_552_064)
    let stamp = try #require(body["checked_at"] as? String)
    #expect(stamp.hasSuffix("Z"))
    #expect(ISO8601DateFormatter().date(from: stamp) == Date(timeIntervalSince1970: 1_795_024_860))
}

@Test func unreadablePartIsNeverOnline() {
    var snapshot = healthy
    snapshot.containers = nil
    snapshot.unreadable = [.containers]
    let result = verdict(for: snapshot, action: nil, externalBusy: false)
    #expect(result.overall == .degraded)
    #expect(result.reason == "Can't read the containers")
    #expect(healthRows(snapshot: snapshot, overall: result.overall)[1].value == "Can't read")
}

@Test func noConfiguredBridgesIsNeverOnline() {
    var snapshot = healthy
    snapshot.bridges = []
    #expect(verdict(for: snapshot, action: nil, externalBusy: false).overall == .degraded)
}

@Test func refusedDriveIsNamedAndNotBlamedOnTheStack() {
    var snapshot = healthy
    snapshot.driveBlocked = true
    let result = verdict(for: snapshot, action: nil, externalBusy: false)
    #expect(result.overall == .degraded)
    #expect(result.reason == driveBlockedMessage)
    #expect(!restartWouldHelp(snapshot))
    let progress = ActionProgress(step: .waiting, finished: true, exitCode: actionDriveRefusedExitCode, failedStep: "waiting")
    #expect(failureMessage(progress: progress, lastLogLine: nil).hasPrefix(driveBlockedMessage))
}

@Test func restartIsOnlyRecommendedForFaultsTheStackOwns() {
    var ollamaDown = healthy
    ollamaDown.ollama = OllamaReading(version: nil, loadedModels: 0)
    #expect(verdict(for: ollamaDown, action: nil, externalBusy: false).overall == .degraded)
    #expect(!restartWouldHelp(ollamaDown))

    var tunnelDown = healthy
    tunnelDown.tunnel = TunnelReading(jobRunning: false, readyConnections: nil)
    #expect(!restartWouldHelp(tunnelDown))

    var containerDown = healthy
    containerDown.containers = ContainerReading(running: 12, total: 13, notRunning: ["queue"])
    #expect(restartWouldHelp(containerDown))

    var bridgeDown = healthy
    bridgeDown.bridges = [BridgeReading(name: "AI", ok: false)]
    #expect(restartWouldHelp(bridgeDown))
}

@Test func readingAMissingFileIsUnavailableNotBlocked() async {
    #expect(await readFile("/nonexistent/menu-bar-test", timeout: 2) == .unavailable)
}

@Test func stalledReadsShareOneWorkerAndRecover() async throws {
    let path = NSTemporaryDirectory() + "menu-bar-stall-\(UUID().uuidString)"
    #expect(mkfifo(path, 0o600) == 0)
    defer { unlink(path) }
    // Opening a pipe with no writer blocks, the same way an unanswered privacy question does.
    for _ in 0..<5 { #expect(await readFile(path, timeout: 0.05) == .blocked) }
    #expect(isFileReadOutstanding(path))

    let writer = open(path, O_WRONLY)
    close(writer)
    for _ in 0..<100 where isFileReadOutstanding(path) { try await Task.sleep(for: .milliseconds(20)) }
    #expect(!isFileReadOutstanding(path))

    unlink(path)
    try "fresh".write(toFile: path, atomically: true, encoding: .utf8)
    #expect(await readFile(path, timeout: 2) == .text("fresh"))
}

@Test func commandReturnsItsOutputAndExitCode() async {
    let result = await runCommand("/bin/sh", ["-c", "echo hello; exit 3"], timeout: 5)
    #expect(result.exitCode == 3)
    #expect(result.text == "hello\n")
    #expect(!result.timedOut)
}

@Test func commandIgnoringSigtermIsStillBounded() async {
    let started = Date()
    let result = await runCommand("/bin/sh", ["-c", "trap '' TERM; sleep 30"], timeout: 0.5)
    #expect(!result.succeeded)
    #expect(result.timedOut)
    #expect(Date().timeIntervalSince(started) < 5)
}

@Test func childHoldingStdoutCannotHangTheCommand() async {
    let started = Date()
    let result = await runCommand("/bin/sh", ["-c", "sleep 30 & echo hello; exit 0"], timeout: 20)
    #expect(result.succeeded)
    #expect(result.text == "hello\n")
    #expect(Date().timeIntervalSince(started) < 5)
}

@Test func progressTracksStepsAndFailure() {
    let text = """
    {"step":"down","state":"start"}
    {"step":"down","state":"end","rc":0}
    {"step":"up","state":"start"}
    {"step":"up","state":"end","rc":1}
    {"step":"done","state":"end","rc":1}
    """
    let progress = parseProgress(text)
    #expect(progress.step == .up)
    #expect(progress.finished)
    #expect(progress.failedStep == "up")
    #expect(!progress.succeeded)
    #expect(failureMessage(progress: progress, lastLogLine: "fpm: timeout").hasPrefix("The containers didn't start cleanly."))
}

@Test func engineUpdateRefusalsSayWhetherTheSiteIsDown() {
    func message(_ code: Int) -> String {
        failureMessage(
            progress: ActionProgress(step: .preflight, finished: true, exitCode: code, failedStep: "preflight"),
            lastLogLine: nil
        )
    }
    #expect(message(actionDeployRunningExitCode).contains("the site was left as it is"))
    #expect(message(actionQueuesBusyExitCode).contains("the site was not stopped"))
    let builder = message(actionBuilderRunningExitCode)
    #expect(builder.contains("The site is down"))
    #expect(builder.contains("builder is still running"))
    #expect(builder.contains("before starting the site"))
}

@Test func busyExitGetsItsOwnMessage() {
    let progress = ActionProgress(step: .waiting, finished: true, exitCode: actionBusyExitCode, failedStep: nil)
    #expect(failureMessage(progress: progress, lastLogLine: nil).hasPrefix("Another session"))
}
