import AppKit
import MothershipBarCore
import SwiftUI

enum FlapLamp: Equatable { case live, working, hold, idle, waiting, new }
enum FlapTone: Equatable { case normal, hold, quiet, new }

/// One row of the board. Cells before `statusStart` are the name, and only the status takes the row's colour.
struct FlapLine: Equatable, Identifiable {
    var id: String
    var cells: [Character]
    var statusStart = 0
    var tone: FlapTone
    var lamp: FlapLamp
    var spoken: String
}

extension EnvironmentValues {
    /// True while the panel is on screen. The board only moves then.
    @Entry var flapIsLive = false
    /// Draws the entrance frozen at this many seconds, for the review filmstrip.
    @Entry var flapFrozenAt: TimeInterval? = nil
}

enum FlapMetrics {
    static let rowCells = 25
    static let nameCells = 10
    static let stripCells = 16
    static let padding: CGFloat = 16
    static let lampColumn: CGFloat = 22
    static let cellGap: CGFloat = 2
    static let rowHeight: CGFloat = 23
    static let rowGap: CGFloat = 4
    static let stripHeight: CGFloat = 38
    static let headingHeight: CGFloat = 26
    static let width = PanelView.width - 16 - padding * 2

    static func canvasHeight(rows: Int) -> CGFloat {
        stripHeight + headingHeight + CGFloat(rows) * rowHeight + CGFloat(max(rows - 1, 0)) * rowGap
    }

    static func rowY(_ index: Int) -> CGFloat {
        stripHeight + headingHeight + CGFloat(index) * (rowHeight + rowGap)
    }
}

private enum FlapInk {
    static let board = Color(nsColor: Theme.rgb(0x0E0E0E))
    static let top = Color(nsColor: Theme.rgb(0x232323))
    static let bottom = Color(nsColor: Theme.rgb(0x1B1B1B))
    static let seam = Color(nsColor: Theme.rgb(0x050505))
    static let character = Color(nsColor: Theme.rgb(0xEDEDED))
    static let dim = Color(nsColor: Theme.rgb(0x8C8C8C))
    static let seen = Color(nsColor: Theme.rgb(0x9A9A9A))
    static let hold = Color(nsColor: Theme.rgb(0xFF7A66))
    static let holdLamp = Color(nsColor: Theme.rgb(0xFF5A45))
    static let new = Color(nsColor: Theme.rgb(0xF2B705))
    static let live = Color(nsColor: Theme.rgb(0x4672F2))
    static let unlit = Color(nsColor: Theme.rgb(0x3A3A3A))
}

/// Home's arrivals board, sized for the panel: a header bar, a headline strip, column headings and service rows.
struct FlapBoardView: View {
    var title: String
    var status: String
    var statusIsLive: Bool
    var headline: FlapLine
    var headings: (name: String, status: String)
    var rows: [FlapLine]
    /// The row that opens a page, and what opening it does.
    var actionRowID: String?
    var action: () -> Void = {}
    /// Square bottom corners, because the yellow deck continues the object.
    var joinsDeck = false

    @Environment(\.flapIsLive) private var isLive
    @Environment(\.flapFrozenAt) private var frozenAt
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown: [[Character]] = []
    @State private var run: Run?
    @State private var actionRowHovered = false

    private struct Run: Equatable {
        let id = UUID()
        let start: Date
        let plan: FlapPlan
        let isEntrance: Bool
    }

    private var target: [[Character]] { [headline.cells] + rows.map(\.cells) }
    private var blank: [[Character]] { target.map { Array(repeating: flapBlank, count: $0.count) } }
    private var moves: Bool { isLive && !reduceMotion && frozenAt == nil }
    private var shape: UnevenRoundedRectangle {
        UnevenRoundedRectangle(cornerRadii: .init(
            topLeading: 18, bottomLeading: joinsDeck ? 0 : 18, bottomTrailing: joinsDeck ? 0 : 18, topTrailing: 18
        ))
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            headerBar
            board
        }
        .padding(FlapMetrics.padding)
        .background(FlapInk.board, in: shape)
        .overlay { shape.strokeBorder(.white.opacity(0.06), lineWidth: 1).allowsHitTesting(false) }
        .onAppear { begin(from: blank, isEntrance: true) }
        .onChange(of: target) { begin(from: visibleGlyphs(), isEntrance: false) }
        .onChange(of: isLive) { begin(from: blank, isEntrance: true) }
        .onChange(of: reduceMotion) { begin(from: visibleGlyphs(), isEntrance: false) }
        .task(id: run?.id) {
            guard let run else { return }
            try? await Task.sleep(for: .seconds(run.plan.duration))
            guard !Task.isCancelled else { return }
            shown = run.plan.target
            self.run = nil
        }
    }

    private var headerBar: some View {
        HStack(spacing: 10) {
            Text(title.uppercased()).foregroundStyle(FlapInk.character)
            Spacer(minLength: 8)
            if statusIsLive {
                RoundedRectangle(cornerRadius: 2)
                    .fill(FlapInk.live)
                    .frame(width: 9, height: 9)
                    .background(FlapInk.live.opacity(0.22), in: RoundedRectangle(cornerRadius: 4).inset(by: -4))
                    .padding(.trailing, 2)
                    .accessibilityHidden(true)
            }
            Text(status.uppercased()).foregroundStyle(FlapInk.dim)
        }
        .font(BrandFont.mono(10.5))
        .lineLimit(1)
    }

    private var board: some View {
        TimelineView(.animation(minimumInterval: 1.0 / 60, paused: run == nil)) { timeline in
            Canvas { context, _ in draw(in: &context, now: timeline.date) }
        }
        .frame(width: FlapMetrics.width, height: FlapMetrics.canvasHeight(rows: rows.count))
        .accessibilityHidden(true)
        .overlay(alignment: .topLeading) { workingLamp }
        .overlay(alignment: .topLeading) { spokenRows }
    }

    /// Breathes only while work is in flight, as Home's live lamp does.
    @ViewBuilder private var workingLamp: some View {
        if headline.lamp == .working, moves {
            BreathingLamp()
                .frame(width: 10, height: 10)
                .offset(x: 2, y: FlapMetrics.stripHeight / 2 - 5)
                .accessibilityHidden(true)
        }
    }

    /// One sentence per row for assistive technology. The cells themselves are hidden from it.
    private var spokenRows: some View {
        ZStack(alignment: .topLeading) {
            Color.clear
                .frame(width: FlapMetrics.width, height: FlapMetrics.stripHeight)
                .accessibilityElement()
                .accessibilityLabel(headline.spoken)
            ForEach(Array(rows.enumerated()), id: \.element.id) { index, row in
                rowHitArea(row)
                    .frame(width: FlapMetrics.width + 8, height: FlapMetrics.rowHeight + 2)
                    .offset(x: -4, y: FlapMetrics.rowY(index) - 1)
            }
        }
    }

    @ViewBuilder private func rowHitArea(_ row: FlapLine) -> some View {
        if row.id == actionRowID {
            Button(action: action) {
                RoundedRectangle(cornerRadius: 5)
                    .fill(.white.opacity(actionRowHovered ? 0.08 : 0))
                    .contentShape(.rect)
            }
            .buttonStyle(.plain)
            .onHover { actionRowHovered = $0 }
            .accessibilityLabel(row.spoken)
        } else {
            Color.clear.accessibilityElement().accessibilityLabel(row.spoken)
        }
    }

    // MARK: Motion

    private func visibleGlyphs() -> [[Character]] {
        guard let run else { return shown }
        return run.plan.glyphs(at: Date().timeIntervalSince(run.start))
    }

    private func begin(from source: [[Character]], isEntrance: Bool) {
        guard moves else {
            shown = target
            run = nil
            return
        }
        let plan = flapPlan(from: source, to: target)
        guard plan.duration > 0 else {
            shown = target
            run = nil
            return
        }
        // A health reading that lands during the arrival must not light the rows still on their way.
        run = Run(start: Date(), plan: plan, isEntrance: isEntrance || run?.isEntrance == true)
    }

    /// On arrival a row's lamp lights as the row settles, as on Home.
    private func lampOpacity(row: Int, now: Date) -> Double {
        let fade = 0.24
        let plan: FlapPlan
        let elapsed: TimeInterval
        if let frozenAt {
            plan = flapPlan(from: blank, to: target)
            elapsed = frozenAt
        } else if let run, run.isEntrance, run.plan.target == target {
            plan = run.plan
            elapsed = now.timeIntervalSince(run.start)
        } else {
            return 1
        }
        guard let end = plan.cells[row].compactMap({ $0?.end }).max() else { return 1 }
        return min(max((elapsed - (end - fade)) / fade, 0), 1)
    }

    private func frame(row: Int, column: Int, now: Date) -> FlapFrame {
        if let frozenAt {
            return flapPlan(from: blank, to: target).frame(row: row, column: column, at: frozenAt)
        }
        if let run, run.plan.target == target {
            return run.plan.frame(row: row, column: column, at: now.timeIntervalSince(run.start))
        }
        if moves, shown.indices.contains(row), shown[row].indices.contains(column) {
            return FlapFrame(glyph: shown[row][column], scale: 1)
        }
        return FlapFrame(glyph: target[row][column], scale: 1)
    }

    // MARK: Drawing

    private struct GlyphKey: Hashable {
        var glyph: Character
        var ink: Int
        var large: Bool
    }

    private func draw(in context: inout GraphicsContext, now: Date) {
        var glyphs: [GlyphKey: GraphicsContext.ResolvedText] = [:]
        let inks = [FlapInk.character, FlapInk.seen, FlapInk.hold, FlapInk.new]
        let smallFont = BrandFont.mono(11.5, weight: 500)
        let largeFont = BrandFont.mono(19, weight: 500)

        func resolved(_ glyph: Character, ink: Int, large: Bool) -> GraphicsContext.ResolvedText {
            let key = GlyphKey(glyph: glyph, ink: ink, large: large)
            if let found = glyphs[key] { return found }
            let text = context.resolve(Text(String(glyph)).font(large ? largeFont : smallFont).foregroundStyle(inks[ink]))
            glyphs[key] = text
            return text
        }

        func drawRow(_ line: FlapLine, row: Int, y: CGFloat, height: CGFloat, large: Bool) {
            if !(line.lamp == .working && moves) {
                drawLamp(
                    line.lamp, in: &context, center: CGPoint(x: 7, y: y + height / 2), isHeadline: large,
                    opacity: lampOpacity(row: row, now: now)
                )
            }
            let count = line.cells.count
            let span = FlapMetrics.width - FlapMetrics.lampColumn
            let width = (span - CGFloat(count - 1) * FlapMetrics.cellGap) / CGFloat(count)
            for column in 0..<count {
                // The gap between the name and the status is empty board, as on Home.
                if !large, column == FlapMetrics.nameCells { continue }
                let x = FlapMetrics.lampColumn + CGFloat(column) * (width + FlapMetrics.cellGap)
                let rect = CGRect(x: x, y: y, width: width, height: height)
                let cell = self.frame(row: row, column: column, now: now)
                let ink = inkIndex(line, column: column)
                let text = cell.glyph == flapBlank ? nil : resolved(cell.glyph, ink: ink, large: large)
                drawCell(text, in: &context, rect: rect, scale: cell.scale, capHeight: (large ? 19 : 11.5) * 0.8)
            }
        }

        drawRow(headline, row: 0, y: 0, height: FlapMetrics.stripHeight, large: true)

        let headingY = FlapMetrics.stripHeight + FlapMetrics.headingHeight - 8
        let span = FlapMetrics.width - FlapMetrics.lampColumn
        let cell = (span + FlapMetrics.cellGap) / CGFloat(FlapMetrics.rowCells)
        for (text, column) in [(headings.name, 0), (headings.status, FlapMetrics.nameCells + 1)] {
            let label = context.resolve(Text(text.uppercased()).font(BrandFont.mono(10)).foregroundStyle(FlapInk.dim))
            context.draw(label, at: CGPoint(x: FlapMetrics.lampColumn + CGFloat(column) * cell, y: headingY), anchor: .bottomLeading)
        }

        for (index, line) in rows.enumerated() {
            drawRow(line, row: index + 1, y: FlapMetrics.rowY(index), height: FlapMetrics.rowHeight, large: false)
        }
    }

    private func inkIndex(_ line: FlapLine, column: Int) -> Int {
        switch line.tone {
        case .quiet: 1
        case .normal: 0
        case .hold: column >= line.statusStart ? 2 : 0
        case .new: column >= line.statusStart ? 3 : 0
        }
    }

    private func drawCell(
        _ text: GraphicsContext.ResolvedText?, in context: inout GraphicsContext, rect: CGRect, scale: Double, capHeight: CGFloat
    ) {
        var cell = context
        if scale != 1 {
            cell.translateBy(x: 0, y: rect.midY)
            cell.scaleBy(x: 1, y: scale)
            cell.translateBy(x: 0, y: -rect.midY)
        }
        cell.clip(to: Path(roundedRect: rect, cornerRadius: 3))
        cell.fill(Path(CGRect(x: rect.minX, y: rect.minY, width: rect.width, height: rect.height / 2)), with: .color(FlapInk.top))
        cell.fill(Path(CGRect(x: rect.minX, y: rect.midY, width: rect.width, height: rect.height / 2)), with: .color(FlapInk.bottom))
        cell.fill(Path(CGRect(x: rect.minX, y: rect.minY, width: rect.width, height: 1)), with: .color(.white.opacity(0.05)))
        cell.fill(Path(CGRect(x: rect.minX, y: rect.maxY - 1, width: rect.width, height: 1)), with: .color(.black.opacity(0.4)))
        // The seam sits behind the glyph, so it never strikes through it.
        cell.fill(Path(CGRect(x: rect.minX, y: rect.midY - 0.5, width: rect.width, height: 1)), with: .color(FlapInk.seam))
        cell.fill(Path(CGRect(x: rect.minX, y: rect.midY + 0.5, width: rect.width, height: 1)), with: .color(.white.opacity(0.035)))
        guard let text else { return }
        let size = text.measure(in: CGSize(width: 40, height: 40))
        let baseline = text.firstBaseline(in: size)
        let origin = CGPoint(x: rect.midX - size.width / 2, y: rect.midY + capHeight / 2 - baseline)
        cell.draw(text, in: CGRect(origin: origin, size: size))
    }

    /// Halos are kept for the headline and for what needs a look, so a healthy board stays quiet.
    private func drawLamp(
        _ lamp: FlapLamp, in context: inout GraphicsContext, center: CGPoint, isHeadline: Bool, opacity: Double
    ) {
        var lit = context
        lit.opacity = opacity
        let side: CGFloat = lamp == .live && !isHeadline ? 8 : 10
        let rect = CGRect(x: center.x - side / 2, y: center.y - side / 2, width: side, height: side)
        let halo = rect.insetBy(dx: -4, dy: -4)
        switch lamp {
        case .live, .working:
            if isHeadline {
                lit.fill(Path(roundedRect: halo, cornerRadius: 5), with: .color(FlapInk.live.opacity(0.22)))
            }
            lit.fill(Path(roundedRect: rect, cornerRadius: 2), with: .color(FlapInk.live))
        case .hold:
            lit.fill(Path(ellipseIn: halo), with: .color(FlapInk.holdLamp.opacity(0.18)))
            lit.fill(Path(ellipseIn: rect), with: .color(FlapInk.holdLamp))
        case .new:
            lit.fill(Path(ellipseIn: halo), with: .color(FlapInk.new.opacity(0.16)))
            lit.fill(Path(ellipseIn: rect), with: .color(FlapInk.new))
        case .idle:
            lit.stroke(Path(ellipseIn: rect.insetBy(dx: 1, dy: 1)), with: .color(FlapInk.dim), lineWidth: 2)
        case .waiting:
            lit.fill(Path(ellipseIn: rect), with: .color(FlapInk.unlit))
        }
    }
}

private struct BreathingLamp: View {
    @State private var isBright = false

    var body: some View {
        RoundedRectangle(cornerRadius: 2)
            .fill(FlapInk.live)
            .background(FlapInk.live.opacity(0.22), in: RoundedRectangle(cornerRadius: 5).inset(by: -4))
            .opacity(isBright ? 1 : 0.55)
            .onAppear {
                withAnimation(.easeInOut(duration: 1.3).repeatForever(autoreverses: true)) { isBright = true }
            }
    }
}

/// The yellow deck that continues the board when something new has arrived.
struct NewDeck: View {
    var count: Int
    /// False while the panel already shows a stronger next step, such as Start Site or Try Again.
    var isNextStep: Bool
    var action: () -> Void

    var body: some View {
        HStack(spacing: 14) {
            Text("\(count)")
                .font(BrandFont.sans(46, weight: 600))
                .tracking(-1.5)
                .monospacedDigit()
            VStack(alignment: .leading, spacing: 1) {
                Text(count == 1 ? "ArtCraft update" : "ArtCraft updates")
                    .font(BrandFont.sans(16, weight: 600))
                Text("Ready to review")
                    .font(BrandFont.sans(13))
                    .opacity(0.72)
            }
            .fixedSize()
            Spacer(minLength: 8)
            Button(action: action) {
                HStack(spacing: 6) {
                    Text("Review")
                    Image(systemName: "arrow.right").font(.system(size: 12, weight: .semibold)).accessibilityHidden(true)
                }
            }
            .buttonStyle(StudioButtonStyle(kind: isNextStep ? .primary : .onNewField))
            .frame(width: 104)
        }
        .foregroundStyle(Theme.onNewField)
        .padding(.horizontal, FlapMetrics.padding)
        .padding(.vertical, 8)
        .background(Theme.newField, in: UnevenRoundedRectangle(cornerRadii: .init(bottomLeading: 18, bottomTrailing: 18)))
    }
}
