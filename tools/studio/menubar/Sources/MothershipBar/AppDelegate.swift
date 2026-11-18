import AppKit
import MothershipBarCore
import Observation
import SwiftUI

private final class StudioPanel: NSPanel {
    var onCancel: (() -> Void)?
    override var canBecomeKey: Bool { true }
    override func cancelOperation(_ sender: Any?) { onCancel?() }
}

private struct PanelHost: View {
    var model: AppModel
    var onSize: (CGSize) -> Void
    var onTheme: (PanelTheme) -> Void

    var body: some View {
        PanelScroller(maxHeight: model.panelMaxHeight) {
            PanelView(state: model.panelState, actions: model.actions)
        }
            .environment(\.flapIsLive, model.panelIsOpen)
            .environment(\.colorScheme, model.theme.colorScheme)
            .clipShape(.rect(cornerRadius: 26))
            .overlay { RoundedRectangle(cornerRadius: 26).strokeBorder(Theme.border, lineWidth: 1) }
            .onGeometryChange(for: CGSize.self, of: \.size, action: onSize)
            .onChange(of: model.theme, initial: true) { onTheme(model.theme) }
    }
}

@MainActor
final class AppDelegate: NSObject, NSApplicationDelegate {
    private let launchedAtLogin: Bool
    private let model = AppModel()
    private var statusItem: NSStatusItem?
    private var panel: StudioPanel?
    private var outsideClickMonitor: Any?
    private var animationTimer: Timer?
    private var frame = 0
    private var lastLoggedVerdict: Verdict?

    init(launchedAtLogin: Bool) {
        self.launchedAtLogin = launchedAtLogin
    }

    func applicationDidFinishLaunching(_ notification: Notification) {
        let item = NSStatusBar.system.statusItem(withLength: NSStatusItem.variableLength)
        item.button?.target = self
        item.button?.action = #selector(togglePanel)
        item.button?.imagePosition = .imageOnly
        statusItem = item
        model.start(launchedAtLogin: launchedAtLogin)
        observeVerdict()
    }

    private func observeVerdict() {
        withObservationTracking {
            updateStatusItem(model.verdict)
        } onChange: { [weak self] in
            Task { @MainActor in self?.observeVerdict() }
        }
    }

    private func updateStatusItem(_ verdict: Verdict) {
        if verdict != lastLoggedVerdict {
            lastLoggedVerdict = verdict
            BarLog.write("info", "verdict.changed", loc: "AppDelegate.updateStatusItem", ctx: [
                "overall": verdict.overall.rawValue, "reason": verdict.reason,
            ])
        }
        let reduceMotion = NSWorkspace.shared.accessibilityDisplayShouldReduceMotion
        statusItem?.button?.image = Glyph.image(Glyph.look(for: verdict.overall, frame: frame, reduceMotion: reduceMotion))
        statusItem?.button?.toolTip = "The Mothership: \(verdict.overall.headline). \(verdict.reason)"
        statusItem?.button?.setAccessibilityLabel("The Mothership: \(verdict.overall.headline)")

        let shouldAnimate = verdict.overall.isChanging && !reduceMotion
        if shouldAnimate, animationTimer == nil {
            animationTimer = Timer.scheduledTimer(withTimeInterval: 0.45, repeats: true) { [weak self] _ in
                MainActor.assumeIsolated { self?.advanceFrame() }
            }
        } else if !shouldAnimate {
            animationTimer?.invalidate()
            animationTimer = nil
        }
    }

    private func advanceFrame() {
        frame += 1
        updateStatusItem(model.verdict)
    }

    @objc private func togglePanel() {
        if panel?.isVisible == true { closePanel() } else { openPanel() }
    }

    private func openPanel() {
        let panel = self.panel ?? makePanel()
        self.panel = panel
        if let screen = statusItem?.button?.window?.screen {
            model.panelMaxHeight = screen.visibleFrame.height - 16
        }
        position(panel, size: panel.frame.size)
        model.panelIsOpen = true
        panel.makeKeyAndOrderFront(nil)
        outsideClickMonitor = NSEvent.addGlobalMonitorForEvents(matching: [.leftMouseDown, .rightMouseDown]) { [weak self] _ in
            MainActor.assumeIsolated { self?.closePanel() }
        }
    }

    private func closePanel() {
        panel?.orderOut(nil)
        model.panelIsOpen = false
        if let outsideClickMonitor { NSEvent.removeMonitor(outsideClickMonitor) }
        outsideClickMonitor = nil
    }

    private func makePanel() -> StudioPanel {
        let panel = StudioPanel(
            contentRect: NSRect(x: 0, y: 0, width: PanelView.width, height: 480),
            styleMask: [.borderless, .nonactivatingPanel], backing: .buffered, defer: false
        )
        panel.isOpaque = false
        panel.backgroundColor = .clear
        panel.hasShadow = true
        panel.level = .statusBar
        panel.collectionBehavior = [.canJoinAllSpaces, .fullScreenAuxiliary]
        panel.isReleasedWhenClosed = false
        panel.onCancel = { [weak self] in self?.closePanel() }
        panel.appearance = model.theme.appearance
        let host = NSHostingView(rootView: PanelHost(model: model, onSize: { [weak self, weak panel] size in
            guard let self, let panel else { return }
            self.position(panel, size: size)
        }, onTheme: { [weak panel] theme in
            panel?.appearance = theme.appearance
        }))
        host.sizingOptions = [.intrinsicContentSize]
        host.safeAreaRegions = []
        panel.contentView = host
        return panel
    }

    /// Hangs the panel under the menu bar item and keeps its top edge fixed as the content changes height.
    private func position(_ panel: NSPanel, size: CGSize) {
        guard size.height > 0, let buttonWindow = statusItem?.button?.window, let screen = buttonWindow.screen else { return }
        let anchor = buttonWindow.frame
        let margin: CGFloat = 8
        let visible = screen.visibleFrame
        let x = min(max(anchor.midX - size.width / 2, visible.minX + margin), visible.maxX - size.width - margin)
        let y = anchor.minY - margin - size.height
        panel.setFrame(NSRect(x: x, y: y, width: size.width, height: size.height), display: true)
    }
}
