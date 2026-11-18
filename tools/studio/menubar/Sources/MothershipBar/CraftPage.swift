import MothershipBarCore
import SwiftUI

struct CraftPanel {
    var status: CraftStatus?
    var isChecking: Bool
    var checkFailed: Bool
    var checkedText: String
    var isOpen: Bool
}

/// ArtCraft updates: what is installed, what is new, and the three things that can be done about it.
struct CraftPage: View {
    var state: PanelState
    var craft: CraftPanel
    var actions: PanelActions

    private var isChanging: Bool { state.verdict.overall.isChanging }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            header
            FlapBoardView(
                title: "Update board", status: craft.isChecking ? "Checking now" : craft.checkedText, statusIsLive: false,
                headline: headline, headings: (name: "Part", status: "Status"), rows: boardRows
            )
            if let status = craft.status {
                table(status).studioCard()
                serverRow(status.mcp).studioCard()
                controls(status)
            } else {
                emptyState.studioCard()
            }
            footer
        }
        .padding(8)
        .frame(width: PanelView.width)
        .background(Theme.deck)
        .foregroundStyle(Theme.text)
    }

    private var header: some View {
        HStack(spacing: 8) {
            Button(action: actions.closeCraft) {
                HStack(spacing: 6) {
                    Image(systemName: "chevron.left").font(.system(size: 12, weight: .semibold))
                    Text("Ship status").font(BrandFont.sans(15, weight: 600))
                }
                .frame(height: 28)
                .contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Back to the site")
            Spacer()
            Button(action: actions.checkCraft) {
                Image(systemName: "arrow.clockwise").frame(width: 28, height: 28).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .foregroundStyle(Theme.textMuted)
            .disabled(craft.isChecking)
            .help("Check for updates")
            .accessibilityLabel("Check for updates")
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 2)
    }

    // MARK: Board

    private var headline: FlapLine {
        let clock = isChanging ? state.elapsedSeconds.map(Format.clock) : nil
        let updates = craft.status?.appsToUpdate ?? 0
        return FlapLine(
            id: "headline",
            cells: flapHeadlineCells("ArtCraft", trailing: clock, cells: FlapMetrics.stripCells),
            tone: .normal,
            lamp: isChanging ? .working : updates > 0 ? .new : craft.status == nil ? .waiting : .live,
            spoken: updates == 1 ? "ArtCraft: 1 app to update" : "ArtCraft: \(updates) apps to update"
        )
    }

    private var boardRows: [FlapLine] {
        guard let status = craft.status else {
            let value = craftRowValue(status: nil, isChecking: craft.isChecking, checkFailed: craft.checkFailed)
            return [row("Updates", value: value, tone: .quiet, lamp: craft.isChecking ? .waiting : .idle)]
        }
        let mcp = status.mcp
        return [
            updateRow("Apps", count: status.desktopUpdates),
            updateRow("Engines", count: status.engineUpdates),
            row(
                "MCP server",
                value: mcp.stale > 0 ? "\(mcp.stale) out of date" : "\(mcp.servers) running",
                tone: mcp.stale > 0 ? .hold : .normal,
                lamp: mcp.stale > 0 ? .hold : .live
            ),
        ]
    }

    private func updateRow(_ name: String, count: Int) -> FlapLine {
        count > 0
            ? row(name, value: "\(count) to update", tone: .new, lamp: .new)
            : row(name, value: "Up to date", tone: .normal, lamp: .live)
    }

    private func row(_ name: String, value: String, tone: FlapTone, lamp: FlapLamp) -> FlapLine {
        FlapLine(
            id: name,
            cells: flapRowCells(name: name, value: value, nameCells: FlapMetrics.nameCells, cells: FlapMetrics.rowCells),
            statusStart: FlapMetrics.nameCells + 1, tone: tone, lamp: lamp, spoken: "\(name): \(value)"
        )
    }

    // MARK: Versions

    private func table(_ status: CraftStatus) -> some View {
        Grid(alignment: .leading, horizontalSpacing: 8, verticalSpacing: 6) {
            GridRow {
                ForEach(["Name", "App", "Engine", "Latest"], id: \.self) { title in
                    CapsLabel(title)
                }
            }
            ForEach(status.apps) { app in
                GridRow {
                    Text(app.name).font(BrandFont.sans(14)).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                    VersionCell(version: app.desktop, isOutdated: app.desktopUpdate)
                    VersionCell(version: app.engine, isOutdated: app.engineUpdate)
                    VersionCell(version: app.latest, isOutdated: false)
                }
                .accessibilityElement(children: .combine)
            }
        }
    }

    private func serverRow(_ mcp: CraftMCPStatus) -> some View {
        HStack(spacing: 8) {
            VStack(alignment: .leading, spacing: 2) {
                Text("MCP server").font(BrandFont.sans(14, weight: 500))
                Text(serverDetail(mcp))
                    .font(BrandFont.sans(13))
                    .foregroundStyle(Theme.textMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 8)
            Button("Restart") { actions.request(.craftMCP) }
                .buttonStyle(StudioButtonStyle(kind: .secondary))
                .frame(width: 88)
                .disabled(isChanging)
        }
    }

    private func serverDetail(_ mcp: CraftMCPStatus) -> String {
        let running = mcp.servers == 1 ? "1 running" : "\(mcp.servers) running"
        let sessions = mcp.openSessions == 1 ? "1 session open" : "\(mcp.openSessions) sessions open"
        let parts = [running, mcp.stale > 0 ? "\(mcp.stale) out of date" : nil, sessions, mcp.keyRender ? "key render running" : nil]
        return parts.compactMap { $0 }.joined(separator: ", ")
    }

    // MARK: Controls

    @ViewBuilder private func controls(_ status: CraftStatus) -> some View {
        if let confirming = state.confirming {
            ConfirmBlock(copy: confirmCopy(for: confirming, mcp: status.mcp), actions: actions)
        } else if let failure = state.failure {
            FailureBlock(failure: failure, actions: actions)
        } else if isChanging {
            VStack(alignment: .leading, spacing: 4) {
                Text(state.stepTitle ?? "Waiting for the other session").font(BrandFont.sans(14, weight: 500))
                Text(state.verdict.reason)
                    .font(BrandFont.sans(13))
                    .foregroundStyle(Theme.textMuted)
                    .fixedSize(horizontal: false, vertical: true)
            }
            .studioCard()
        } else {
            updateButtons(status)
        }
    }

    private func updateButtons(_ status: CraftStatus) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                Button("Update Apps") { actions.request(.craftApps) }
                    .buttonStyle(StudioButtonStyle(kind: status.desktopUpdates > 0 ? .primary : .secondary))
                    .disabled(status.desktopUpdates == 0)
                Button("Update Engines") { actions.request(.craftEngines) }
                    .buttonStyle(StudioButtonStyle(kind: .secondary))
                    .disabled(status.engineUpdates == 0)
            }
            Text(updateNote(status))
                .font(BrandFont.sans(13))
                .foregroundStyle(Theme.textMuted)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 10)
        }
    }

    private func updateNote(_ status: CraftStatus) -> String {
        let outage = "Updating engines stops the site while they build."
        switch status.openAppsSkipped {
        case 0: return outage
        case 1: return "1 open app will be skipped. \(outage)"
        default: return "\(status.openAppsSkipped) open apps will be skipped. \(outage)"
        }
    }

    private var emptyState: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(craft.isChecking ? "Checking for updates" : "Updates can't be checked")
                .font(BrandFont.sans(15, weight: 600))
            Text(craft.isChecking
                ? "This asks for the latest version of each app."
                : "The update tool didn't answer. Check again in a moment.")
                .font(BrandFont.sans(14))
                .foregroundStyle(Theme.textMuted)
                .fixedSize(horizontal: false, vertical: true)
            if !craft.isChecking {
                Button("Check Now", action: actions.checkCraft).buttonStyle(StudioButtonStyle(kind: .primary))
            }
        }
    }

    @ViewBuilder private var footer: some View {
        if craft.checkFailed, craft.status != nil {
            HStack(spacing: 6) {
                Image(systemName: "exclamationmark.triangle.fill")
                    .font(.system(size: 10, weight: .semibold))
                    .accessibilityHidden(true)
                Text("Last check failed").font(BrandFont.sans(13))
            }
            .foregroundStyle(Theme.alertText)
            .padding(.horizontal, 10)
            .padding(.vertical, 2)
        }
    }
}

/// A version, with an arrow when a newer one exists, so the difference never rests on weight alone.
private struct VersionCell: View {
    var version: String?
    var isOutdated: Bool

    var body: some View {
        HStack(spacing: 2) {
            Text(version ?? "–")
                .font(BrandFont.mono(11, weight: isOutdated ? 600 : 400))
                .foregroundStyle(isOutdated ? Theme.text : Theme.textMuted)
                .lineLimit(1)
                .fixedSize()
            if isOutdated {
                Image(systemName: "arrow.up")
                    .font(.system(size: 9, weight: .bold))
                    .accessibilityLabel("Update available")
            }
        }
    }
}
