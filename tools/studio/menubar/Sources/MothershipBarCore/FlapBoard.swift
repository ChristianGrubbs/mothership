import Foundation

/// Home's split-flap timing (`redesign/v2-bridge/assets/motion`). Rows are closer than Home's 80 ms so that the
/// panel's eight rows arrive inside Home's one-second limit.
public enum FlapTiming {
    public static let step: TimeInterval = 0.055
    public static let minimumPassing = 3
    public static let passingSpread = 4
    public static let rowDelay: TimeInterval = 0.06
    public static let columnDelay: TimeInterval = 0.006
    /// How far a cell squashes at the middle of each step.
    public static let squash = 0.82
}

public let flapBlank: Character = " "
private let flapPassing = Array("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789")

/// Capitals, cut with an ellipsis when too long, padded with blanks on the right.
public func flapCells(_ text: String, count: Int) -> [Character] {
    guard count > 0 else { return [] }
    var cells = Array(text.uppercased())
    if cells.count > count { cells = Array(cells.prefix(count - 1)) + ["…"] }
    return cells + Array(repeating: flapBlank, count: count - cells.count)
}

/// A service row: the name, one gap cell, then the status. `trailing` takes the last cell.
public func flapRowCells(name: String, value: String, nameCells: Int, cells: Int, trailing: Character? = nil) -> [Character] {
    let valueCells = max(cells - nameCells - 1 - (trailing == nil ? 0 : 1), 0)
    var row = flapCells(name, count: nameCells) + [flapBlank] + flapCells(value, count: valueCells)
    if let trailing { row.append(trailing) }
    return row
}

/// The headline, with an optional clock held against the right edge.
public func flapHeadlineCells(_ text: String, trailing: String?, cells: Int) -> [Character] {
    guard let trailing, trailing.count < cells else { return flapCells(text, count: cells) }
    return flapCells(text, count: cells - trailing.count - 1) + [flapBlank] + Array(trailing.uppercased())
}

/// What one cell shows at a moment. `scale` is its height, 1 at rest.
public struct FlapFrame: Sendable, Equatable {
    public var glyph: Character
    public var scale: Double

    public init(glyph: Character, scale: Double) {
        self.glyph = glyph
        self.scale = scale
    }
}

public struct FlapCell: Sendable, Equatable {
    /// The old glyph, the passing glyphs, then the final glyph.
    public var glyphs: [Character]
    public var delay: TimeInterval

    public init(glyphs: [Character], delay: TimeInterval) {
        self.glyphs = glyphs
        self.delay = delay
    }

    public var end: TimeInterval { delay + Double(glyphs.count - 1) * FlapTiming.step }

    public func frame(at elapsed: TimeInterval) -> FlapFrame {
        let running = elapsed - delay
        guard running >= 0 else { return FlapFrame(glyph: glyphs[0], scale: 1) }
        guard elapsed < end else { return FlapFrame(glyph: glyphs[glyphs.count - 1], scale: 1) }
        let position = running / FlapTiming.step
        // The first passing glyph shows the moment the cell's turn comes.
        let index = min(Int(position) + 1, glyphs.count - 1)
        let phase = position - Double(Int(position))
        let depth = 1 - abs(2 * phase - 1)
        return FlapFrame(glyph: glyphs[index], scale: 1 - (1 - FlapTiming.squash) * depth)
    }
}

/// One change of the board, from what it shows to what it should show. Unchanged cells have no plan and stay still.
public struct FlapPlan: Sendable, Equatable {
    public var target: [[Character]]
    public var cells: [[FlapCell?]]

    public var duration: TimeInterval {
        cells.joined().compactMap { $0?.end }.max() ?? 0
    }

    public func frame(row: Int, column: Int, at elapsed: TimeInterval) -> FlapFrame {
        if let cell = cells[row][column] { return cell.frame(at: elapsed) }
        return FlapFrame(glyph: target[row][column], scale: 1)
    }

    /// The board as it reads at a moment, so that a new change can start from a board already in motion.
    public func glyphs(at elapsed: TimeInterval) -> [[Character]] {
        target.indices.map { row in
            target[row].indices.map { column in frame(row: row, column: column, at: elapsed).glyph }
        }
    }
}

public func flapPlan(from source: [[Character]], to target: [[Character]]) -> FlapPlan {
    let cells: [[FlapCell?]] = target.indices.map { row in
        target[row].indices.map { column -> FlapCell? in
            let final = target[row][column]
            let old = source.indices.contains(row) && source[row].indices.contains(column) ? source[row][column] : flapBlank
            guard old != final else { return nil }
            let delay = Double(row) * FlapTiming.rowDelay + Double(column) * FlapTiming.columnDelay
            // A cell going blank drops once. Only characters arriving pass through the alphabet.
            guard final != flapBlank else { return FlapCell(glyphs: [old, final], delay: delay) }
            let seed = row + 1
            let count = FlapTiming.minimumPassing + (column * 7 + seed * 3) % FlapTiming.passingSpread
            let passing = (0..<count).map { step in
                flapPassing[(step * 11 + column * 5 + seed) % flapPassing.count]
            }
            return FlapCell(glyphs: [old] + passing + [final], delay: delay)
        }
    }
    return FlapPlan(target: target, cells: cells)
}
