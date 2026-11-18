import AppKit
import MothershipBarCore
import Observation

@MainActor
@Observable
final class AppModel {
    private(set) var snapshot = HealthSnapshot()
    private(set) var externalBusy = false
    private(set) var activeAction: ActionRunner.Current?
    private(set) var progress: ActionProgress?
    private(set) var failure: PanelState.Failure?
    private(set) var confirming: ActionKind?
    private(set) var startAtLogin: Bool
    private(set) var lastChecked: Date?
    private(set) var actionStarted: Date?
    private(set) var now = Date()
    private(set) var craftStatus: CraftStatus?
    private(set) var craftChecking = false
    private(set) var craftCheckFailed = false
    private(set) var showingCraft = false
    var panelIsOpen = false { didSet { if panelIsOpen { wake() } } }
    var theme = PanelTheme.stored { didSet { theme.store() } }
    var panelMaxHeight: CGFloat = 800

    @ObservationIgnored private let config: BarConfig?
    @ObservationIgnored private var lastFailedKind: ActionKind?
    @ObservationIgnored private var lastLogID: String?
    @ObservationIgnored private var pollTask: Task<Void, Never>?
    @ObservationIgnored private var wakeRequested = false
    @ObservationIgnored private var idleSleep: Task<Void, Never>?
    @ObservationIgnored private var lastStatsRead = Date.distantPast
    @ObservationIgnored private var lastProcessRead: Date?
    @ObservationIgnored private var lastProcessReadSucceeded = true
    @ObservationIgnored private var siteOKAtProcessRead: Bool?
    @ObservationIgnored private var tunnelJobRunning = false
    @ObservationIgnored private var heldContainers = HeldReading<ContainerReading>()
    @ObservationIgnored private var heldBridges = HeldReading<[BridgeReading]>()
    @ObservationIgnored private var storeState = StoreState.checking
    @ObservationIgnored private var lastStoreRead: Date?
    @ObservationIgnored private var storeReadRunning = false
    @ObservationIgnored private var lastCraftAttempt: Date?

    private var probes: Probes? { config.map { Probes(config: $0) } }
    private var runner: ActionRunner? { config.map { ActionRunner(config: $0) } }

    init() {
        config = (try? Data(contentsOf: BarPaths.config)).flatMap { try? JSONDecoder().decode(BarConfig.self, from: $0) }
        startAtLogin = (try? String(contentsOf: BarPaths.preferences, encoding: .utf8))?.hasPrefix("off") != true
        storeState = config?.umbrelStore?.sshArguments == nil ? .notSetUp : .checking
        snapshot.store = storeState
        craftStatus = (try? String(contentsOf: BarPaths.craftStatus, encoding: .utf8)).flatMap(parseCraftStatus)
    }

    var verdict: Verdict {
        guard config != nil else {
            return Verdict(overall: .unconfigured, reason: "Run the installer to connect this app to the stack.")
        }
        return MothershipBarCore.verdict(for: snapshot, action: activeAction?.kind, externalBusy: externalBusy)
    }

    var panelState: PanelState {
        PanelState(
            verdict: verdict,
            snapshot: snapshot,
            stepTitle: activeAction == nil ? nil : (progress?.step ?? .waiting).title,
            elapsedSeconds: actionStarted.map { max(0, Int(now.timeIntervalSince($0))) },
            failure: failure,
            confirming: confirming,
            checkedText: checkedText,
            startAtLogin: startAtLogin,
            craft: config?.craftTool == nil ? nil : CraftPanel(
                status: craftStatus, isChecking: craftChecking, checkFailed: craftCheckFailed,
                checkedText: craftCheckedText, isOpen: showingCraft
            )
        )
    }

    private var craftCheckedText: String {
        guard let checked = craftStatus?.checkedDate else { return "Not checked yet" }
        return "Checked \(Format.uptimeShort(max(0, Int(now.timeIntervalSince(checked))))) ago"
    }

    private var checkedText: String {
        guard let lastChecked else { return "Not checked yet" }
        let seconds = max(0, Int(now.timeIntervalSince(lastChecked)))
        return seconds < 3 ? "Checked just now" : "Checked \(Format.uptimeShort(seconds)) ago"
    }

    var actions: PanelActions {
        PanelActions(
            request: { [weak self] kind in self?.request(kind) },
            confirm: { [weak self] in self?.confirmPending() },
            cancelConfirm: { [weak self] in self?.confirming = nil },
            retry: { [weak self] in self?.retry() },
            dismissFailure: { [weak self] in self?.failure = nil },
            openSite: { [weak self] in self?.openSite() },
            openLog: { [weak self] in self?.openLog() },
            openPrivacySettings: {
                if let url = URL(string: "x-apple.systempreferences:com.apple.preference.security?Privacy_AllFiles") {
                    NSWorkspace.shared.open(url)
                }
            },
            setStartAtLogin: { [weak self] enabled in self?.setStartAtLogin(enabled) },
            setTheme: { [weak self] theme in self?.theme = theme },
            quit: { NSApp.terminate(nil) },
            openCraft: { [weak self] in self?.openCraft() },
            closeCraft: { [weak self] in self?.closeCraft() },
            checkCraft: { [weak self] in self?.checkCraft(force: true) }
        )
    }

    func start(launchedAtLogin: Bool) {
        BarLog.write("info", "app.started", loc: "AppModel.start", ctx: ["login": "\(launchedAtLogin)"])
        reattach()
        runner?.pruneOldFiles()
        if launchedAtLogin { startForLoginIfNeeded() }
        launchRequestedTestAction()
        pollTask = Task { [weak self] in
            await self?.pollForever()
        }
    }

    private func wake() {
        wakeRequested = true
        idleSleep?.cancel()
    }

    /// Test runs only: `--test-action <kind>` works solely with the isolated `MOTHERSHIP_BAR_HOME`.
    private func launchRequestedTestAction() {
        let arguments = CommandLine.arguments
        guard ProcessInfo.processInfo.environment["MOTHERSHIP_BAR_HOME"] != nil,
              let flag = arguments.firstIndex(of: "--test-action"), arguments.indices.contains(flag + 1),
              let kind = ActionKind(rawValue: arguments[flag + 1]) else { return }
        launch(kind)
    }

    // MARK: Actions

    private func request(_ kind: ActionKind) {
        failure = nil
        switch kind {
        case .start, .login, .craftApps:
            launch(kind)
        case .craftMCP where craftStatus?.mcp.isInUse != true:
            launch(.craftMCP)
        case .craftMCP:
            confirming = .craftMCPForce
        case .stop, .restart, .craftEngines, .craftMCPForce:
            confirming = kind
        }
    }

    private func openCraft() {
        showingCraft = true
        checkCraft(force: false)
    }

    private func closeCraft() {
        showingCraft = false
        confirming = nil
    }

    private func checkCraft(force: Bool) {
        guard let probes, config?.craftTool != nil, !craftChecking else { return }
        guard force || craftCheckIsDue(
            checkedAt: craftStatus?.checkedDate, lastAttempt: lastCraftAttempt, pageIsOpen: showingCraft, now: Date()
        ) else { return }
        craftChecking = true
        lastCraftAttempt = Date()
        Task { [weak self] in
            let status = await probes.craftCheck()
            self?.finishCraftCheck(status)
        }
    }

    private func finishCraftCheck(_ status: CraftStatus?) {
        craftChecking = false
        craftCheckFailed = status == nil
        if let status { craftStatus = status }
    }

    private func confirmPending() {
        guard let kind = confirming else { return }
        confirming = nil
        launch(kind)
    }

    private func retry() {
        // Goes through `request`, so a retry that interrupts someone still asks first.
        let kind = lastFailedKind ?? .start
        request(kind == .login ? .start : kind)
    }

    private func launch(_ kind: ActionKind) {
        guard activeAction == nil, !externalBusy, let runner else { return }
        do {
            activeAction = try runner.launch(kind)
            actionStarted = Date()
            progress = nil
            lastLogID = activeAction?.id
            wake()
        } catch {
            BarLog.write("error", "action.launch_failed", loc: "AppModel.launch", ctx: ["kind": kind.rawValue])
            failure = PanelState.Failure(title: kind.failureTitle, message: "The stack command couldn't be launched.")
            lastFailedKind = kind
        }
    }

    /// Picks up an action that was already running when this app started.
    private func reattach() {
        guard let runner, let current = runner.recorded() else { return }
        lastLogID = current.id
        let recorded = runner.progress(of: current.id)
        guard !recorded.finished, runner.runnerIsAlive() else { return }
        activeAction = current
        progress = recorded
        let attributes = try? FileManager.default.attributesOfItem(atPath: runner.progressFile(current.id).path)
        actionStarted = attributes?[.creationDate] as? Date ?? Date()
        BarLog.write("info", "action.reattached", loc: "AppModel.reattach", ctx: ["id": current.id])
    }

    /// The only automatic start. The app never restarts the stack because it looks down:
    /// a planned deploy keeps it down on purpose.
    private func startForLoginIfNeeded() {
        guard startAtLogin, config != nil else { return }
        let previous = try? String(contentsOf: BarPaths.loginMarker, encoding: .utf8)
        guard previous != LoginSession.current() else {
            BarLog.write("info", "login.already_handled", loc: "AppModel.startForLoginIfNeeded")
            return
        }
        _ = LoginSession.markHandled()
        launch(.login)
    }

    private func setStartAtLogin(_ enabled: Bool) {
        startAtLogin = enabled
        try? FileManager.default.createDirectory(at: BarPaths.root, withIntermediateDirectories: true)
        try? (enabled ? "on" : "off").write(to: BarPaths.preferences, atomically: true, encoding: .utf8)
        BarLog.write("info", "preference.start_at_login", loc: "AppModel.setStartAtLogin", ctx: ["enabled": "\(enabled)"])
    }

    private func openSite() {
        guard let text = config?.siteURL, let url = URL(string: text), var parts = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return }
        parts.path = "/"
        if let target = parts.url { NSWorkspace.shared.open(target) }
    }

    private func openLog() {
        guard let runner else { return }
        if let id = lastLogID ?? runner.recorded()?.id, FileManager.default.fileExists(atPath: runner.logFile(id).path) {
            NSWorkspace.shared.open(runner.logFile(id))
        } else {
            NSWorkspace.shared.open(BarPaths.root)
        }
    }

    // MARK: Polling

    private func pollForever() async {
        while !Task.isCancelled {
            await pollOnce()
            await waitForNextPoll()
            wakeRequested = false
        }
    }

    /// Ticks each second only while someone can see the result. Otherwise the app sleeps until the next poll.
    private func waitForNextPoll() async {
        let deadline = Date().addingTimeInterval(healthPollInterval)
        while Date() < deadline, !Task.isCancelled, !wakeRequested {
            if panelIsOpen || activeAction != nil {
                now = Date()
                trackAction()
                try? await Task.sleep(for: .seconds(1))
            } else {
                let sleep = Task<Void, Never> {
                    try? await Task.sleep(for: .seconds(deadline.timeIntervalSinceNow), tolerance: .seconds(5))
                }
                idleSleep = sleep
                await sleep.value
                idleSleep = nil
            }
        }
    }

    private func trackAction() {
        guard let runner, let current = activeAction else { return }
        let latest = runner.progress(of: current.id)
        progress = latest
        if latest.finished {
            finish(current, latest)
        } else if !runner.runnerIsAlive(), Date().timeIntervalSince(actionStarted ?? Date()) > 10 {
            finish(current, ActionProgress(step: latest.step, finished: true, exitCode: 1, failedStep: latest.failedStep))
        }
    }

    private func finish(_ current: ActionRunner.Current, _ result: ActionProgress) {
        activeAction = nil
        actionStarted = nil
        BarLog.write("info", "action.finished", loc: "AppModel.finish", ctx: ["id": current.id, "exit": "\(result.exitCode ?? -1)"])
        if result.succeeded {
            failure = nil
        } else {
            lastFailedKind = current.kind
            failure = PanelState.Failure(
                title: current.kind.failureTitle,
                message: failureMessage(progress: result, lastLogLine: runner?.lastLogLine(of: current.id))
            )
        }
        if current.kind.isCraft { checkCraft(force: true) }
        lastProcessRead = nil
        wake()
    }

    /// Runs apart from the health poll, so a slow Umbrel never delays the site's status.
    private func refreshStoreIfDue() {
        guard let probes, storeState != .notSetUp, !storeReadRunning, storeReadIsDue(
            lastRead: lastStoreRead, lastSucceeded: storeState != .unreachable, panelIsOpen: panelIsOpen, now: Date()
        ) else { return }
        storeReadRunning = true
        Task { [weak self] in
            let reading = await probes.umbrelStore()
            self?.finishStoreRead(reading)
        }
    }

    private func finishStoreRead(_ reading: UmbrelStoreReading?) {
        storeReadRunning = false
        lastStoreRead = Date()
        storeState = reading.map(StoreState.ready) ?? .unreachable
        snapshot.store = storeState
    }

    private func pollOnce() async {
        guard let probes else { return }
        refreshStoreIfDue()
        checkCraft(force: false)
        let pollStarted = Date()
        async let siteResult = probes.site()
        async let tunnel = probes.tunnel(lastKnownJobRunning: tunnelJobRunning)
        async let bridges = probes.bridges()
        async let ollama = probes.ollama()
        let readStats = panelIsOpen && pollStarted.timeIntervalSince(lastStatsRead) >= statsReadInterval
        async let stats = probes.stats(includeContainers: readStats, previous: snapshot.stats)

        let site = await siteResult
        let siteOK = site.publicOK && site.originOK
        var containerValue = snapshot.containers
        var containersUnreadable = snapshot.unreadable.contains(.containers)
        let readProcesses = processReadsAreDue(ProcessReadCheck(
            lastRead: lastProcessRead, lastSucceeded: lastProcessReadSucceeded,
            looksHealthy: verdict.overall == .online,
            siteChanged: siteOKAtProcessRead.map { $0 != siteOK } ?? false,
            panelIsOpen: panelIsOpen, actionRunning: activeAction != nil
        ), now: pollStarted)
        if readProcesses {
            async let containers = probes.containers()
            async let job = probes.tunnelJobRunning()
            async let busy = probes.externalChange()
            let containerReading = await containers
            let held = heldContainers.update(containerReading, at: Date())
            containerValue = held.value
            containersUnreadable = held.unreadable
            tunnelJobRunning = await job
            externalBusy = await busy
            lastProcessRead = pollStarted
            lastProcessReadSucceeded = containerReading != nil
            siteOKAtProcessRead = siteOK
        }

        let bridgeResult = await bridges
        let heldBridgeReading = heldBridges.update(bridgeResult.readings, at: Date())
        if bridgeResult.driveBlocked != snapshot.driveBlocked {
            BarLog.write(
                bridgeResult.driveBlocked ? "warn" : "info", "probe.drive_access", loc: "AppModel.pollOnce",
                ctx: ["blocked": "\(bridgeResult.driveBlocked)"]
            )
        }

        var next = HealthSnapshot()
        next.site = site
        next.containers = containerValue
        next.tunnel = await tunnel
        next.bridges = heldBridgeReading.value
        next.unreadable = (containersUnreadable ? [.containers] : [])
            + (heldBridgeReading.unreadable ? [.bridges] : [])
        next.driveBlocked = bridgeResult.driveBlocked
        next.ollama = await ollama
        next.stats = await stats
        next.store = storeState
        if readStats { lastStatsRead = Date() }
        snapshot = next
        lastChecked = Date()
        now = Date()
        trackAction()
    }
}

enum LoginSession {
    /// Changes on every login, and stays the same if the app crashes and relaunches.
    static func current() -> String {
        var boot = timeval()
        var size = MemoryLayout<timeval>.size
        sysctlbyname("kern.boottime", &boot, &size, nil, 0)
        let loginWindow = NSRunningApplication.runningApplications(withBundleIdentifier: "com.apple.loginwindow")
            .first?.processIdentifier ?? 0
        return "boot-\(boot.tv_sec)-loginwindow-\(loginWindow)"
    }

    /// Records this login as dealt with, so the login start doesn't run again until the next one.
    static func markHandled() -> Bool {
        do {
            try FileManager.default.createDirectory(at: BarPaths.root, withIntermediateDirectories: true)
            try current().write(to: BarPaths.loginMarker, atomically: true, encoding: .utf8)
            return true
        } catch {
            return false
        }
    }
}

extension Format {
    static func uptimeShort(_ seconds: Int) -> String {
        if seconds < 60 { return "\(seconds) s" }
        return seconds < 3600 ? "\(seconds / 60) m" : "\(seconds / 3600) h"
    }
}
