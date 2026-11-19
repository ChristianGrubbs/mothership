import AppKit
import MothershipBarCore
import SwiftUI

/// `--render-states <dir>` writes every panel state and the glyph sheet to PNG for review.
@MainActor
enum RenderStates {
    private static let healthy = HealthSnapshot(
        site: SiteReading(publicStatus: 200, publicMs: 412, originStatus: 200),
        containers: ContainerReading(running: 13, total: 13, notRunning: []),
        tunnel: TunnelReading(jobRunning: true, readyConnections: 4),
        bridges: [
            BridgeReading(name: "Optimizer", ok: true), BridgeReading(name: "AI", ok: true),
            BridgeReading(name: "Video", ok: true),
        ],
        ollama: OllamaReading(version: "0.40.16", loadedModels: 1),
        stats: StatsReading(memoryBytes: 3_412_000_000, uptimeSeconds: 22_320, diskFreeBytes: 1_075_000_000_000),
        store: .ready(UmbrelStoreReading(
            driveTotalBytes: 7_948_830_552_064, driveFreeBytes: 5_474_694_856_704, libraryBytes: 190_700_000
        ))
    )

    private static let craftVersions: [(String, String, String)] = [
        ("GridCraft", "0.11.1", "0.19.1"), ("WordCraft", "0.10.0", "0.18.1"), ("PhotoCraft", "0.14.0", "0.23.1"),
        ("PDFCraft", "0.13.0", "0.21.1"), ("CADCraft", "0.12.0", "0.20.1"), ("DesignCraft", "0.12.0", "0.20.1"),
        ("FigCraft", "0.10.0", "0.18.1"), ("SoundCraft", "0.12.1", "0.20.1"), ("FilmCraft", "0.12.0", "0.20.1"),
        ("DeckCraft", "0.10.0", "0.18.1"), ("VectorCraft", "0.16.0", "0.24.1"), ("EffectCraft", "0.16.0", "0.24.1"),
        ("LightCraft", "0.20.0", "0.21.1"),
    ]

    private static let craftUpdates = CraftStatus(
        checkedAt: "2026-11-18T17:00:00Z",
        apps: craftVersions.map { name, installed, latest in
            CraftApp(
                app: name.lowercased(), name: name, desktop: installed, engine: installed, latest: latest,
                desktopUpdate: true, engineUpdate: true, desktopRunning: name == "PhotoCraft"
            )
        },
        mcp: CraftMCPStatus(servers: 35, stale: 0, openSessions: 0, keyRender: false)
    )

    private static let craftCurrent = CraftStatus(
        checkedAt: "2026-11-18T17:00:00Z",
        apps: craftVersions.map { name, _, latest in
            CraftApp(
                app: name.lowercased(), name: name, desktop: latest, engine: latest, latest: latest,
                desktopUpdate: false, engineUpdate: false, desktopRunning: false
            )
        },
        mcp: CraftMCPStatus(servers: 35, stale: 0, openSessions: 0, keyRender: false)
    )

    private static func craftPanel(_ status: CraftStatus, isOpen: Bool = false) -> CraftPanel {
        CraftPanel(status: status, isChecking: false, checkFailed: false, checkedText: "Checked 42 m ago", isOpen: isOpen)
    }

    private static func fixtures() -> [(String, PanelState)] {
        var degraded = healthy
        degraded.containers = ContainerReading(running: 12, total: 13, notRunning: ["vectorization_queue"])
        degraded.bridges?[1].ok = false

        var offline = healthy
        offline.site = SiteReading(publicStatus: 502, publicMs: 90, originStatus: nil)
        offline.containers = ContainerReading(running: 0, total: 13, notRunning: [])

        var starting = offline
        starting.containers = ContainerReading(running: 7, total: 13, notRunning: [])

        var bridgesDown = healthy
        bridgesDown.bridges = healthy.bridges?.map { BridgeReading(name: $0.name, ok: false) }

        var twoBridgesDown = healthy
        twoBridgesDown.bridges?[0].ok = false
        twoBridgesDown.bridges?[2].ok = false

        var bridgeRestarting = healthy
        bridgeRestarting.bridges?[0].ok = false
        bridgeRestarting.bridgeWatch = ["Optimizer": .restarting]

        var bridgeGaveUp = healthy
        bridgeGaveUp.bridges?[0].ok = false
        bridgeGaveUp.bridgeWatch = ["Optimizer": .noRecovery]

        var unreadable = healthy
        unreadable.containers = nil
        unreadable.bridges = nil
        unreadable.unreadable = [.containers, .bridges]

        var boundaryFigures = healthy
        boundaryFigures.site = SiteReading(publicStatus: 200, publicMs: 999, originStatus: 200)
        boundaryFigures.stats = StatsReading(memoryBytes: 999_400_000_000, uptimeSeconds: 8_726_400, diskFreeBytes: 999_600_000_000)

        var dayBoundary = healthy
        dayBoundary.stats = StatsReading(memoryBytes: 99_900_000_000, uptimeSeconds: 86_399, diskFreeBytes: 100_000_000_000)

        var ollamaDown = healthy
        ollamaDown.ollama = OllamaReading(version: nil, loadedModels: 0)

        var driveBlocked = unreadable
        driveBlocked.driveBlocked = true

        var longFigures = healthy
        longFigures.site = SiteReading(publicStatus: 200, publicMs: 12_400, originStatus: 200)
        longFigures.stats = StatsReading(memoryBytes: 128_400_000_000, uptimeSeconds: 8_380_000, diskFreeBytes: 1_240_000_000_000)

        var storeUnreachable = healthy
        storeUnreachable.store = .unreachable

        var storeChecking = healthy
        storeChecking.store = .checking

        var storeNotSetUp = healthy
        storeNotSetUp.store = .notSetUp

        var storeServiceDown = healthy
        storeServiceDown.store = .ready(UmbrelStoreReading(
            driveTotalBytes: 7_948_830_552_064, driveFreeBytes: 5_474_694_856_704, libraryBytes: nil
        ))

        var storeLarge = healthy
        storeLarge.store = .ready(UmbrelStoreReading(
            driveTotalBytes: 7_948_830_552_064, driveFreeBytes: 412_000_000_000, libraryBytes: 7_120_000_000_000
        ))

        func panel(
            _ snapshot: HealthSnapshot, action: ActionKind? = nil, busy: Bool = false, step: String? = nil,
            elapsed: Int? = nil, failure: PanelState.Failure? = nil, confirming: ActionKind? = nil,
            queues: QueueCheck? = nil, siteResult: String? = nil, craft: CraftPanel? = craftPanel(craftUpdates)
        ) -> PanelState {
            PanelState(
                verdict: verdict(for: snapshot, action: action, externalBusy: busy),
                snapshot: snapshot, stepTitle: step, elapsedSeconds: elapsed, failure: failure,
                confirming: confirming, queueCheck: queues, siteResult: siteResult,
                checkedText: "Checked 8 s ago", startAtLogin: true, craft: craft
            )
        }

        var craftBusy = craftUpdates
        craftBusy.mcp = CraftMCPStatus(servers: 35, stale: 2, openSessions: 2, keyRender: true)

        let craftFailure = PanelState.Failure(
            title: ActionKind.craftEngines.failureTitle, message: "The engines didn't all build. The site was started again."
        )

        let failure = PanelState.Failure(
            title: "Start failed",
            message: "The containers didn't start cleanly. fpm did not become ready within 300 seconds"
        )
        return [
            ("1-online", panel(healthy)),
            ("2-offline", panel(offline)),
            ("3-degraded", panel(degraded)),
            ("4-starting", panel(starting, action: .start, step: ActionStep.up.title, elapsed: 74)),
            ("5-restarting", panel(starting, action: .restart, step: ActionStep.down.title, elapsed: 12)),
            ("6-busy", panel(healthy, busy: true)),
            ("7-confirm-stop", panel(healthy, confirming: .stop)),
            ("7a-confirm-stop-reading", panel(healthy, confirming: .stop, queues: .reading)),
            ("7b-confirm-stop-idle", panel(healthy, confirming: .stop, queues: .read(QueueReading(jobs: 0, uploads: 0)))),
            ("7c-confirm-stop-busy", panel(healthy, confirming: .stop, queues: .read(QueueReading(jobs: 12, uploads: 1)))),
            ("7d-confirm-stop-unreadable", panel(healthy, confirming: .stop, queues: .unreadable)),
            ("8-confirm-restart", panel(healthy, confirming: .restart)),
            ("8a-confirm-restart-busy", panel(healthy, confirming: .restart, queues: .read(QueueReading(jobs: 1, uploads: 0)))),
            ("8b-restart-result", panel(healthy, siteResult: siteResultMessage(for: .restart, seconds: 74))),
            ("9-failed", panel(offline, failure: failure)),
            ("10-checking", panel(HealthSnapshot())),
            ("11-stopping", panel(starting, action: .stop, step: ActionStep.down.title, elapsed: 21)),
            ("12-bridges-all-down", panel(bridgesDown)),
            ("13-bridges-two-down", panel(twoBridgesDown)),
            ("13a-bridge-restarting", panel(bridgeRestarting)),
            ("13b-bridge-gave-up", panel(bridgeGaveUp)),
            ("14-unreadable", panel(unreadable)),
            ("15-long-figures", panel(longFigures)),
            ("16-ollama-down", panel(ollamaDown)),
            ("17-drive-blocked", panel(driveBlocked)),
            ("18-boundary-figures", panel(boundaryFigures)),
            ("19-day-boundary", panel(dayBoundary)),
            ("20-store-unreachable", panel(storeUnreachable)),
            ("21-store-checking", panel(storeChecking)),
            ("22-store-not-set-up", panel(storeNotSetUp)),
            ("23-store-service-down", panel(storeServiceDown)),
            ("24-store-large", panel(storeLarge)),
            ("25-craft-updates", panel(healthy, craft: craftPanel(craftUpdates, isOpen: true))),
            ("26-craft-current", panel(healthy, craft: craftPanel(craftCurrent, isOpen: true))),
            ("27-craft-confirm-engines", panel(healthy, confirming: .craftEngines, craft: craftPanel(craftUpdates, isOpen: true))),
            ("28-craft-confirm-server", panel(healthy, confirming: .craftMCPForce, craft: craftPanel(craftBusy, isOpen: true))),
            ("29-craft-building", panel(
                offline, action: .craftEngines, step: ActionStep.build.title, elapsed: 312,
                craft: craftPanel(craftUpdates, isOpen: true)
            )),
            ("30-craft-failed", panel(healthy, failure: craftFailure, craft: craftPanel(craftUpdates, isOpen: true))),
            ("31-craft-checking", panel(healthy, craft: CraftPanel(
                status: nil, isChecking: true, checkFailed: false, checkedText: "Not checked yet", isOpen: true
            ))),
            ("32-craft-check-failed", panel(healthy, craft: CraftPanel(
                status: nil, isChecking: false, checkFailed: true, checkedText: "Not checked yet", isOpen: true
            ))),
            ("33-craft-stale-check", panel(healthy, craft: CraftPanel(
                status: craftBusy, isChecking: false, checkFailed: true, checkedText: "Checked 7 h ago", isOpen: true
            ))),
            ("34-craft-row-current", panel(healthy, craft: craftPanel(craftCurrent))),
        ]
    }

    /// `only` limits the render to fixtures whose names start with one of these prefixes.
    static func run(directory: URL, only: [String] = []) throws {
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let all = fixtures()
        let chosen = only.isEmpty ? all : all.filter { fixture in only.contains { fixture.0.hasPrefix($0) } }
        for (name, state) in chosen {
            for (suffix, scheme, appearance) in [
                ("light", ColorScheme.light, NSAppearance.Name.aqua), ("dark", ColorScheme.dark, NSAppearance.Name.darkAqua),
            ] {
                let view = PanelView(state: state, actions: PanelActions()).environment(\.colorScheme, scheme)
                try write(view: view, appearance: appearance, to: directory.appending(path: "panel-\(name)-\(suffix).png"))
            }
        }
        // The tallest page on a display too short to hold it.
        if let tallest = all.first(where: { $0.0.hasPrefix("27-") })?.1 {
            let view = PanelScroller(maxHeight: 560) { PanelView(state: tallest, actions: PanelActions()) }
                .environment(\.colorScheme, .dark)
            try write(view: view, appearance: .darkAqua, to: directory.appending(path: "panel-short-display-dark.png"))
        }
        // The entrance, frozen at moments through the flip.
        if let hero = all.first?.1 {
            for milliseconds in [40, 140, 260, 400, 560, 900] {
                let view = PanelView(state: hero, actions: PanelActions())
                    .environment(\.colorScheme, .dark)
                    .environment(\.flapFrozenAt, Double(milliseconds) / 1000)
                try write(view: view, appearance: .darkAqua, to: directory.appending(path: "flip-\(milliseconds)ms.png"))
            }
        }
        try writeGlyphSheet(to: directory.appending(path: "glyph-sheet.png"))
    }

    static func runLive(directory: URL) async throws {
        let config = try JSONDecoder().decode(BarConfig.self, from: Data(contentsOf: BarPaths.config))
        let probes = Probes(config: config)
        async let site = probes.site()
        async let containers = probes.containers()
        async let tunnel = probes.tunnel(lastKnownJobRunning: probes.tunnelJobRunning())
        async let bridges = probes.bridges()
        async let ollama = probes.ollama()
        async let stats = probes.stats(includeContainers: true, previous: nil)
        async let store = probes.umbrelStore()
        let snapshot = await HealthSnapshot(
            site: site, containers: containers, tunnel: tunnel, bridges: bridges.readings, ollama: ollama, stats: stats,
            store: store.map(StoreState.ready) ?? .unreachable
        )
        let state = PanelState(
            verdict: verdict(for: snapshot, action: nil, externalBusy: false), snapshot: snapshot, stepTitle: nil,
            elapsedSeconds: nil, failure: nil, confirming: nil, checkedText: "Checked just now", startAtLogin: true
        )
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        for (suffix, scheme, appearance) in [
            ("light", ColorScheme.light, NSAppearance.Name.aqua), ("dark", ColorScheme.dark, NSAppearance.Name.darkAqua),
        ] {
            let view = PanelView(state: state, actions: PanelActions()).environment(\.colorScheme, scheme)
            try write(view: view, appearance: appearance, to: directory.appending(path: "panel-live-\(suffix).png"))
        }
    }

    private static func write<V: View>(view: V, appearance: NSAppearance.Name, to url: URL) throws {
        let host = NSHostingView(rootView: view)
        host.safeAreaRegions = []
        host.appearance = NSAppearance(named: appearance)
        let first = host.fittingSize
        let window = NSWindow(
            contentRect: NSRect(origin: .zero, size: first), styleMask: .borderless, backing: .buffered, defer: false
        )
        window.contentView = host
        host.frame = NSRect(origin: .zero, size: first)
        host.layoutSubtreeIfNeeded()
        RunLoop.main.run(until: Date().addingTimeInterval(0.25))
        // Menus and switches settle after their first layout, so the picture is sized from the settled page.
        let size = host.fittingSize
        if size != first {
            FileHandle.standardError.write(Data("\(url.lastPathComponent): \(first.height) settled to \(size.height)\n".utf8))
            window.setContentSize(size)
            host.frame = NSRect(origin: .zero, size: size)
            host.layoutSubtreeIfNeeded()
            RunLoop.main.run(until: Date().addingTimeInterval(0.25))
        }
        try png(of: host, size: size, to: url)
    }

    private static func png(of view: NSView, size: NSSize, to url: URL) throws {
        guard let bitmap = NSBitmapImageRep(
            bitmapDataPlanes: nil, pixelsWide: Int(size.width * 2), pixelsHigh: Int(size.height * 2),
            bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false,
            colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0
        ) else { throw CocoaError(.fileWriteUnknown) }
        bitmap.size = size
        view.cacheDisplay(in: view.bounds, to: bitmap)
        guard let data = bitmap.representation(using: .png, properties: [:]) else { throw CocoaError(.fileWriteUnknown) }
        try data.write(to: url)
    }

    private static func writeGlyphSheet(to url: URL) throws {
        let looks: [Glyph.Look] = [
            .online, .offline, .checking, .changing(frame: 0), .changing(frame: 1), .changing(frame: 2),
            .changingStill, .degraded,
        ]
        let cell: CGFloat = 44
        let zoomedCell: CGFloat = 120
        let width = CGFloat(looks.count) * zoomedCell
        let height = (cell + zoomedCell) * 2
        guard let bitmap = NSBitmapImageRep(
            bitmapDataPlanes: nil, pixelsWide: Int(width * 2), pixelsHigh: Int(height * 2),
            bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true, isPlanar: false,
            colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0
        ), let context = NSGraphicsContext(bitmapImageRep: bitmap) else { throw CocoaError(.fileWriteUnknown) }
        bitmap.size = NSSize(width: width, height: height)
        NSGraphicsContext.current = context
        context.cgContext.scaleBy(x: 2, y: 2)

        for (band, colours) in [(0, (ground: 0xF2F2F2, ink: 0x141414)), (1, (ground: 0x1F1F1F, ink: 0xFFFFFF))] as [(Int, (ground: UInt32, ink: UInt32))] {
            let bandY = CGFloat(band) * (cell + zoomedCell)
            Theme.rgb(colours.ground).setFill()
            NSRect(x: 0, y: bandY, width: width, height: cell + zoomedCell).fill()
            for (index, look) in looks.enumerated() {
                let x = CGFloat(index) * zoomedCell
                drawGlyph(look, ink: Theme.rgb(colours.ink), origin: NSPoint(x: x + 16, y: bandY + 14), scale: 1, height: Glyph.side)
                drawGlyph(look, ink: Theme.rgb(colours.ink), origin: NSPoint(x: x + 16, y: bandY + cell + 8), scale: 5, height: Glyph.side)
            }
        }
        NSGraphicsContext.current = nil
        guard let data = bitmap.representation(using: .png, properties: [:]) else { throw CocoaError(.fileWriteUnknown) }
        try data.write(to: url)
    }

    private static func drawGlyph(_ look: Glyph.Look, ink: NSColor, origin: NSPoint, scale: CGFloat, height: CGFloat) {
        let transform = NSAffineTransform()
        transform.translateX(by: origin.x, yBy: origin.y + height * scale)
        transform.scaleX(by: scale, yBy: -scale)
        NSGraphicsContext.saveGraphicsState()
        transform.concat()
        Glyph.draw(look, ink: ink)
        NSGraphicsContext.restoreGraphicsState()
    }
}
