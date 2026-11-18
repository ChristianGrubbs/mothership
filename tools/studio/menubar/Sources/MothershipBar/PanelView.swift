import AppKit
import MothershipBarCore
import SwiftUI

struct PanelState {
    struct Failure: Equatable {
        var title: String
        var message: String
    }

    var verdict: Verdict
    var snapshot: HealthSnapshot
    var stepTitle: String?
    var elapsedSeconds: Int?
    var failure: Failure?
    var confirming: ActionKind?
    var checkedText: String
    var startAtLogin: Bool
    /// Nil when this Mac has no ArtCraft update tool.
    var craft: CraftPanel?
}

struct PanelActions {
    var request: (ActionKind) -> Void = { _ in }
    var confirm: () -> Void = {}
    var cancelConfirm: () -> Void = {}
    var retry: () -> Void = {}
    var dismissFailure: () -> Void = {}
    var openSite: () -> Void = {}
    var openLog: () -> Void = {}
    var openPrivacySettings: () -> Void = {}
    var setStartAtLogin: (Bool) -> Void = { _ in }
    var setTheme: (PanelTheme) -> Void = { _ in }
    var quit: () -> Void = {}
    var openCraft: () -> Void = {}
    var closeCraft: () -> Void = {}
    var checkCraft: () -> Void = {}
}

/// Keeps every control reachable on a short display. The panel scrolls only when it can't fit.
struct PanelScroller<Content: View>: View {
    var maxHeight: CGFloat
    @ViewBuilder var content: Content
    @State private var contentHeight: CGFloat?

    var body: some View {
        ScrollView(.vertical) {
            content.onGeometryChange(for: CGFloat.self, of: \.size.height) { contentHeight = $0 }
        }
        .scrollBounceBehavior(.basedOnSize)
        // A visible scroll bar takes width from the 400-point page and pushes it sideways.
        .scrollIndicators(.never)
        .frame(width: PanelView.width, height: min(contentHeight ?? maxHeight, maxHeight))
    }
}

struct PanelView: View {
    nonisolated static let width: CGFloat = 400

    var state: PanelState
    var actions: PanelActions

    private var overall: Overall { state.verdict.overall }

    var body: some View {
        if let craft = state.craft, craft.isOpen {
            CraftPage(state: state, craft: craft, actions: actions)
        } else {
            stackPage
        }
    }

    private var stackPage: some View {
        VStack(alignment: .leading, spacing: 8) {
            header
            boardAndDeck
            reasonBlock
            if overall != .unconfigured {
                VStack(alignment: .leading, spacing: 14) {
                    if overall.isChanging { progressBlock } else { figures }
                    storage
                }
                .studioCard()
            }
            controls
            footer
        }
        .padding(8)
        .frame(width: Self.width)
        .background(Theme.deck)
        .foregroundStyle(Theme.text)
    }

    /// Board cells cut long faults short, so each fault is written out in full here.
    private var faults: [String] {
        guard overall == .degraded, !state.snapshot.driveBlocked else { return [] }
        return detailedProblems(in: state.snapshot)
    }

    private var reasonBlock: some View {
        VStack(alignment: .leading, spacing: 3) {
            if faults.isEmpty {
                Text(state.verdict.reason)
            } else {
                ForEach(faults, id: \.self) { Text($0) }
            }
        }
        .font(BrandFont.sans(14))
        .foregroundStyle(overall == .degraded ? Theme.alertText : Theme.textMuted)
        .fixedSize(horizontal: false, vertical: true)
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
    }

    private var header: some View {
        HStack(spacing: 6) {
            Lockup()
            Spacer()
            ThemeSegment(setTheme: actions.setTheme).fixedSize()
            Button(action: actions.openSite) {
                Image(systemName: "arrow.up.right").frame(width: 28, height: 28).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .foregroundStyle(Theme.textMuted)
            .help("Open the site")
            .accessibilityLabel("Open the site")

            Menu {
                Button("Open Latest Log", action: actions.openLog)
                Divider()
                Button("Quit Menu Bar App (Site Keeps Running)", action: actions.quit)
            } label: {
                Image(systemName: "ellipsis").frame(width: 28, height: 28).contentShape(.rect)
            }
            .menuStyle(.button)
            .buttonStyle(.plain)
            .menuIndicator(.hidden)
            .fixedSize()
            .foregroundStyle(Theme.textMuted)
            .accessibilityLabel("More")
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 2)
    }

    // MARK: Board

    private var updateCount: Int {
        guard !overall.isChanging else { return 0 }
        return state.craft?.status?.appsToUpdate ?? 0
    }

    private var boardAndDeck: some View {
        VStack(spacing: 0) {
            FlapBoardView(
                title: "Ship status", status: state.checkedText, statusIsLive: overall == .online,
                headline: headlineLine, headings: (name: "Service", status: "Status"), rows: boardRows,
                actionRowID: state.craft == nil ? nil : "ArtCraft", action: actions.openCraft, joinsDeck: updateCount > 0
            )
            if updateCount > 0 {
                NewDeck(
                    count: updateCount,
                    isNextStep: overall == .online && state.confirming == nil && state.failure == nil,
                    action: actions.openCraft
                )
            }
        }
    }

    private var headlineLine: FlapLine {
        let clock = overall.isChanging ? state.elapsedSeconds.map(Format.clock) : nil
        return FlapLine(
            id: "headline",
            cells: flapHeadlineCells(overall.headline, trailing: clock, cells: FlapMetrics.stripCells),
            tone: overall == .degraded ? .hold : .normal,
            lamp: headlineLamp,
            spoken: "\(overall.headline). \(state.verdict.reason)"
        )
    }

    private var headlineLamp: FlapLamp {
        switch overall {
        case .online: .live
        case .degraded: .hold
        case .starting, .stopping, .restarting, .updating, .busy: .working
        case .offline: .idle
        case .checking, .unconfigured: .waiting
        }
    }

    private var boardRows: [FlapLine] {
        var lines = healthRows(snapshot: state.snapshot, overall: overall).map(line)
        if let craft = state.craft { lines.append(craftLine(craft)) }
        return lines
    }

    private func line(_ row: HealthRow) -> FlapLine {
        let look: (tone: FlapTone, lamp: FlapLamp) = switch row.health {
        case .ok: (.normal, .live)
        case .failing: (.hold, .hold)
        case .off: (.quiet, .idle)
        case .checking: (.quiet, .waiting)
        }
        return FlapLine(
            id: row.name,
            cells: flapRowCells(name: row.name, value: row.value, nameCells: FlapMetrics.nameCells, cells: FlapMetrics.rowCells),
            statusStart: FlapMetrics.nameCells + 1, tone: look.tone, lamp: look.lamp, spoken: "\(row.name): \(row.value)"
        )
    }

    private func craftLine(_ craft: CraftPanel) -> FlapLine {
        let value = craftRowValue(status: craft.status, isChecking: craft.isChecking, checkFailed: craft.checkFailed)
        let hasUpdates = (craft.status?.appsToUpdate ?? 0) > 0
        let look: (tone: FlapTone, lamp: FlapLamp) = hasUpdates
            ? (.new, .new)
            : craft.status == nil ? (.quiet, craft.isChecking ? .waiting : .idle) : (.normal, .live)
        return FlapLine(
            id: "ArtCraft",
            cells: flapRowCells(
                name: "ArtCraft", value: value, nameCells: FlapMetrics.nameCells, cells: FlapMetrics.rowCells, trailing: ">"
            ),
            statusStart: FlapMetrics.nameCells + 1, tone: look.tone, lamp: look.lamp, spoken: "ArtCraft: \(value). Open updates"
        )
    }

    // MARK: Figures

    private var figures: some View {
        let stats = state.snapshot.stats
        let hasTraffic = overall != .offline
        return HStack(alignment: .firstTextBaseline, spacing: 0) {
            Figure(value: hasTraffic ? Format.milliseconds(state.snapshot.site?.publicMs) : "–", label: "Response")
            Figure(value: hasTraffic ? Format.uptime(stats?.uptimeSeconds) : "–", label: "Uptime")
            Figure(value: hasTraffic ? Format.bytes(stats?.memoryBytes.map(Int64.init)) : "–", label: "Memory")
        }
    }

    /// The Umbrel is where assets live, so it leads. This Mac's space is a labelled sentence, and it
    /// never stands in for the Umbrel's figures when the Umbrel can't be read.
    private var storage: some View {
        VStack(alignment: .leading, spacing: 8) {
            CapsLabel("Assets on the Umbrel")
            umbrelFigures.frame(minHeight: 40, alignment: .top)
            Text(macSpaceLine)
                .font(BrandFont.sans(13))
                .foregroundStyle(Theme.textMuted)
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    private var macSpaceLine: String {
        guard let free = state.snapshot.stats?.diskFreeBytes else { return "This Mac: free space can't be read" }
        return "This Mac: \(Format.bytes(free)) free for drafts and staging"
    }

    @ViewBuilder private var umbrelFigures: some View {
        switch state.snapshot.store {
        case .ready(let reading):
            // The free figure sits on the third column of the figures above.
            HStack(alignment: .firstTextBaseline, spacing: 0) {
                Figure(
                    value: Format.bytes(reading.libraryBytes),
                    label: reading.libraryBytes == nil ? "Library size unavailable" : "Used by The Mothership"
                )
                .frame(width: (Self.width - 44) * 2 / 3, alignment: .leading)
                Figure(value: Format.bytes(reading.driveFreeBytes), label: "Free of \(Format.bytes(reading.driveTotalBytes))")
            }
        case .checking:
            StoreNotice(title: "Reading the Umbrel", detail: "Asset space appears here in a moment.", isProblem: false)
        case .unreachable:
            StoreNotice(title: "Umbrel unreachable", detail: "Asset space can't be read right now.", isProblem: true)
        case .notSetUp:
            StoreNotice(title: "Umbrel not connected", detail: "Run the installer to read asset space.", isProblem: true)
        }
    }

    private var progressBlock: some View {
        let containers = state.snapshot.containers
        return VStack(alignment: .leading, spacing: 10) {
            ContainerModules(running: containers?.running ?? 0, total: max(containers?.total ?? 13, 13))
            Text(state.stepTitle ?? "Waiting for the other session").font(BrandFont.sans(14))
        }
    }

    // MARK: Controls

    @ViewBuilder private var controls: some View {
        if let confirming = state.confirming {
            ConfirmBlock(copy: confirmCopy(for: confirming, mcp: state.craft?.status?.mcp), actions: actions)
        } else if let failure = state.failure {
            FailureBlock(failure: failure, actions: actions)
        } else {
            normalControls
        }
    }

    @ViewBuilder private var normalControls: some View {
        switch overall {
        case .offline:
            Button("Start Site") { actions.request(.start) }.buttonStyle(StudioButtonStyle(kind: .primary))
        case .online:
            HStack(spacing: 8) {
                Button("Restart") { actions.request(.restart) }.buttonStyle(StudioButtonStyle(kind: .secondary))
                Button("Stop") { actions.request(.stop) }.buttonStyle(StudioButtonStyle(kind: .secondary))
            }
        case .degraded where state.snapshot.driveBlocked:
            Button("Open Privacy Settings", action: actions.openPrivacySettings)
                .buttonStyle(StudioButtonStyle(kind: .primary))
        case .degraded:
            HStack(spacing: 8) {
                Button("Restart") { actions.request(.restart) }
                    .buttonStyle(StudioButtonStyle(kind: restartWouldHelp(state.snapshot) ? .primary : .secondary))
                Button("Stop") { actions.request(.stop) }.buttonStyle(StudioButtonStyle(kind: .secondary))
            }
        case .checking, .starting, .stopping, .restarting, .updating, .busy, .unconfigured:
            HStack(spacing: 8) {
                Button("Restart") {}.buttonStyle(StudioButtonStyle(kind: .secondary))
                Button("Stop") {}.buttonStyle(StudioButtonStyle(kind: .secondary))
            }
            .disabled(true)
        }
    }

    private var footer: some View {
        Toggle(isOn: Binding(get: { state.startAtLogin }, set: actions.setStartAtLogin)) {
            Text("Start site at login")
                .font(BrandFont.sans(13))
                .foregroundStyle(Theme.textMuted)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .toggleStyle(.switch)
        .controlSize(.mini)
        .tint(Theme.act)
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
    }
}

/// Graphite and White, named and drawn as on the site's top bar.
private struct ThemeSegment: View {
    var setTheme: (PanelTheme) -> Void
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        HStack(spacing: 0) {
            ForEach(PanelTheme.allCases, id: \.self) { theme in
                let isCurrent = theme.colorScheme == colorScheme
                Button { setTheme(theme) } label: {
                    HStack(spacing: 5) {
                        Image(systemName: theme.symbol).font(.system(size: 11, weight: .medium)).accessibilityHidden(true)
                        Text(theme.title).font(BrandFont.sans(12.5, weight: 500))
                    }
                    .foregroundStyle(isCurrent ? Theme.text : Theme.textMuted)
                    .padding(.horizontal, 8)
                    .frame(height: 26)
                    .background(isCurrent ? Theme.well : .clear, in: .rect(cornerRadius: 8))
                    .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("\(theme.title) theme")
                .accessibilityAddTraits(isCurrent ? .isSelected : [])
            }
        }
        .padding(2)
        .background(Theme.card, in: .rect(cornerRadius: 10))
    }
}

/// Asks before an action that interrupts someone.
struct ConfirmBlock: View {
    var copy: ConfirmCopy
    var actions: PanelActions

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(copy.title).font(BrandFont.sans(15, weight: 600))
            Text(copy.message)
                .font(BrandFont.sans(14))
                .foregroundStyle(Theme.textMuted)
                .fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 8) {
                Button("Cancel", action: actions.cancelConfirm)
                    .buttonStyle(StudioButtonStyle(kind: .secondary))
                    .keyboardShortcut(.cancelAction)
                Button(copy.button, action: actions.confirm)
                    .buttonStyle(StudioButtonStyle(kind: copy.isDestructive ? .destructive : .primary))
            }
            .padding(.top, 4)
        }
        .studioCard()
    }
}

struct FailureBlock: View {
    var failure: PanelState.Failure
    var actions: PanelActions

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Label(failure.title, systemImage: "exclamationmark.triangle.fill")
                .font(BrandFont.sans(15, weight: 600))
                .foregroundStyle(Theme.alertText)
            Text(failure.message)
                .font(BrandFont.sans(14))
                .foregroundStyle(Theme.text)
                .lineLimit(4)
                .fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 8) {
                Button("Open Log", action: actions.openLog).buttonStyle(StudioButtonStyle(kind: .secondary))
                Button("Try Again", action: actions.retry).buttonStyle(StudioButtonStyle(kind: .primary))
            }
            .padding(.top, 4)
        }
        .studioCard()
    }
}

private struct Lockup: View {
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        let name = colorScheme == .dark ? "lockup-compact-colour-dark" : "lockup-compact-colour-light"
        if let url = Bundle.main.url(forResource: name, withExtension: "svg"), let image = NSImage(contentsOf: url) {
            Image(nsImage: image)
                .resizable()
                .scaledToFit()
                .frame(height: 24)
                .padding(.leading, -4)
                .accessibilityLabel("The Mothership")
        } else {
            Text("The Mothership").font(BrandFont.sans(15, weight: 600))
        }
    }
}

struct Figure: View {
    var value: String
    var label: String

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(value).font(BrandFont.mono(17, weight: 500)).lineLimit(1).minimumScaleFactor(0.8)
            CapsLabel(label)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .combine)
    }
}

/// Takes the place of the Umbrel's figures when there are none to show.
private struct StoreNotice: View {
    var title: String
    var detail: String
    var isProblem: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack(spacing: 6) {
                if isProblem {
                    Image(systemName: "exclamationmark.triangle.fill")
                        .font(.system(size: 11, weight: .semibold))
                        .accessibilityHidden(true)
                }
                Text(title).font(BrandFont.sans(14, weight: 600))
            }
            .foregroundStyle(isProblem ? Theme.alertText : Theme.text)
            Text(detail).font(BrandFont.sans(13)).foregroundStyle(Theme.textMuted)
        }
        .accessibilityElement(children: .combine)
    }
}

/// One square per container: progress the operator can count.
private struct ContainerModules: View {
    var running: Int
    var total: Int

    var body: some View {
        HStack(spacing: 4) {
            ForEach(0..<total, id: \.self) { index in
                RoundedRectangle(cornerRadius: 2)
                    .fill(index < running ? Theme.live : Theme.moduleEmpty)
                    .frame(height: 16)
            }
        }
        .accessibilityElement()
        .accessibilityLabel("\(running) of \(total) containers running")
    }
}
