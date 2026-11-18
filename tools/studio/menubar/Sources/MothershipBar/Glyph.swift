import AppKit
import MothershipBarCore

/// Draws the brand mark from its 5 × 5 module grid at menu bar size.
/// One module is 3 pt with a 0.5 pt gap, so every edge lands on a Retina pixel.
enum Glyph {
    enum Look: Equatable {
        case online, offline, checking
        case changing(frame: Int)
        case changingStill
        case degraded
    }

    static let side: CGFloat = 17
    private static let module: CGFloat = 3
    private static let pitch: CGFloat = 3.5

    private static let ink: [CGRect] = [
        CGRect(x: 0, y: 0, width: module, height: pitch * 3 + module),
        CGRect(x: pitch * 4, y: 0, width: module, height: pitch * 3 + module),
        CGRect(x: module, y: pitch, width: pitch, height: module),
        CGRect(x: pitch * 3, y: pitch, width: pitch, height: module),
    ]
    private static let centre = cell(column: 2, row: 2)
    private static let left = cell(column: 0, row: 4)
    private static let right = cell(column: 4, row: 4)

    private static func cell(column: Int, row: Int) -> CGRect {
        CGRect(x: CGFloat(column) * pitch, y: CGFloat(row) * pitch, width: module, height: module)
    }

    static func look(for overall: Overall, frame: Int, reduceMotion: Bool) -> Look {
        switch overall {
        case .online: .online
        case .offline, .unconfigured: .offline
        case .checking: .checking
        case .degraded: .degraded
        case .starting, .stopping, .restarting, .updating, .busy: reduceMotion ? .changingStill : .changing(frame: frame)
        }
    }

    static func image(_ look: Look) -> NSImage {
        let image = NSImage(size: NSSize(width: side, height: side), flipped: true) { _ in
            draw(look, ink: look == .degraded ? .labelColor : .black)
            return true
        }
        image.isTemplate = look != .degraded
        image.cacheMode = .never
        return image
    }

    /// Draws at the current context scale. Also used by the review sheet.
    static func draw(_ look: Look, ink inkColor: NSColor) {
        let inkAlpha: CGFloat = (look == .offline || look == .checking) ? 0.45 : 1
        inkColor.withAlphaComponent(inkAlpha).setFill()
        ink.forEach { $0.fill() }

        switch look {
        case .online:
            inkColor.setFill()
            [centre, left, right].forEach { $0.fill() }
        case .offline:
            break
        case .checking:
            inkColor.setFill()
            centre.fill()
        case .changing(let frame):
            inkColor.setFill()
            [centre, left, right][frame % 3].fill()
        case .changingStill:
            inkColor.setFill()
            [left, right].forEach { $0.fill() }
        case .degraded:
            Theme.rgb(0xE5412D).setFill()
            centre.fill()
        }
    }
}
