import AppKit
import CoreText
import SwiftUI

/// The product's two themes. A theme changes colour values only.
enum PanelTheme: String, CaseIterable {
    case graphite, white

    private static let defaultsKey = "panelTheme"

    static var stored: PanelTheme {
        UserDefaults.standard.string(forKey: defaultsKey).flatMap(PanelTheme.init(rawValue:)) ?? .graphite
    }

    func store() { UserDefaults.standard.set(rawValue, forKey: Self.defaultsKey) }

    var title: String { self == .graphite ? "Graphite" : "White" }
    var symbol: String { self == .graphite ? "moon" : "sun.max" }
    var colorScheme: ColorScheme { self == .graphite ? .dark : .light }
    var appearance: NSAppearance? { NSAppearance(named: self == .graphite ? .darkAqua : .aqua) }
}

/// Brand tokens v0.3 (`brand/v1-identity/out/tokens.css`) on the Bridge's grounds, White then Graphite.
enum Theme {
    static let deck = dynamic(0xEFEFEF, 0x0B0B0B)
    static let card = dynamic(0xFFFFFF, 0x161616)
    static let well = dynamic(0xE2E2E2, 0x222222)
    static let wellPressed = dynamic(0xCCCCCC, 0x2A2A2A)
    static let border = dynamic(0xE2E2E2, 0x2A2A2A)
    static let borderStrong = dynamic(0x7A7A7A, 0x7A7A7A)
    static let text = dynamic(0x1B1B1B, 0xF7F7F7)
    static let textMuted = dynamic(0x5C5C5C, 0xA3A3A3)
    static let act = dynamic(0x2D5BE3, 0x2D5BE3)
    static let actHover = dynamic(0x2449C4, 0x2449C4)
    static let live = dynamic(0x2D5BE3, 0x4672F2)
    static let alert = dynamic(0xB8301F, 0xB8301F)
    static let alertText = dynamic(0xB8301F, 0xFF7A66)
    static let focusRing = dynamic(0x2D5BE3, 0x8AA8FF)
    static let moduleEmpty = dynamic(0xCCCCCC, 0x2A2A2A)
    /// The yellow deck, the same in both themes.
    static let newField = Color(nsColor: rgb(0xFFD60A))
    static let onNewField = Color(nsColor: rgb(0x141414))

    private static func dynamic(_ light: UInt32, _ dark: UInt32) -> Color {
        Color(nsColor: NSColor(name: nil) { appearance in
            let isDark = appearance.bestMatch(from: [.darkAqua, .aqua]) == .darkAqua
            return rgb(isDark ? dark : light)
        })
    }

    static func rgb(_ value: UInt32) -> NSColor {
        NSColor(
            srgbRed: CGFloat((value >> 16) & 0xFF) / 255,
            green: CGFloat((value >> 8) & 0xFF) / 255,
            blue: CGFloat(value & 0xFF) / 255,
            alpha: 1
        )
    }
}

/// The site's typefaces, read from the app bundle. Falls back to the system's faces if the files are missing.
enum BrandFont {
    private static let weightAxis = 0x7767_6874
    private static let widthAxis = 0x7764_7468

    nonisolated(unsafe) private static let sansFace = face("instrument-sans-latin-standard-normal")
    nonisolated(unsafe) private static let monoFace = face("martian-mono-latin-standard-normal")

    private static func face(_ name: String) -> CTFontDescriptor? {
        guard let url = Bundle.main.url(forResource: name, withExtension: "woff2", subdirectory: "fonts"),
              let faces = CTFontManagerCreateFontDescriptorsFromURL(url as CFURL) as? [CTFontDescriptor]
        else { return nil }
        return faces.first
    }

    private static func font(_ face: CTFontDescriptor, size: CGFloat, weight: CGFloat) -> Font {
        let weighted = CTFontDescriptorCreateCopyWithVariation(face, weightAxis as CFNumber, weight)
        let sized = CTFontDescriptorCreateCopyWithVariation(weighted, widthAxis as CFNumber, 100)
        return Font(CTFontCreateWithFontDescriptor(sized, size, nil))
    }

    /// Reading text.
    static func sans(_ size: CGFloat, weight: CGFloat = 400) -> Font {
        guard let sansFace else {
            return .system(size: size, weight: weight >= 600 ? .semibold : weight >= 500 ? .medium : .regular)
        }
        return font(sansFace, size: size, weight: weight)
    }

    /// Flap cells, figures, times and capital labels.
    static func mono(_ size: CGFloat, weight: CGFloat = 400) -> Font {
        guard let monoFace else {
            return .system(size: size, weight: weight >= 500 ? .medium : .regular, design: .monospaced)
        }
        return font(monoFace, size: size, weight: weight)
    }
}

/// A capital label in the mono face.
struct CapsLabel: View {
    var text: String

    init(_ text: String) { self.text = text }

    var body: some View {
        Text(text.uppercased())
            .font(BrandFont.mono(10))
            .foregroundStyle(Theme.textMuted)
            .lineLimit(1)
    }
}

/// White on the light deck with a one-pixel shadow, near-black on graphite with a top inner highlight.
private struct StudioCard: ViewModifier {
    @Environment(\.colorScheme) private var colorScheme

    func body(content: Content) -> some View {
        content
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Theme.card, in: .rect(cornerRadius: 18))
            .overlay {
                if colorScheme == .dark {
                    RoundedRectangle(cornerRadius: 18)
                        .strokeBorder(
                            LinearGradient(colors: [.white.opacity(0.07), .clear], startPoint: .top, endPoint: .init(x: 0.5, y: 0.2)),
                            lineWidth: 1
                        )
                        .allowsHitTesting(false)
                }
            }
            .shadow(color: .black.opacity(colorScheme == .dark ? 0 : 0.10), radius: 0.5, y: 1)
    }
}

extension View {
    func studioCard() -> some View { modifier(StudioCard()) }
}

struct StudioButtonStyle: ButtonStyle {
    enum Kind { case primary, secondary, destructive, onNewField }
    var kind: Kind
    @Environment(\.isEnabled) private var isEnabled
    @Environment(\.isFocused) private var isFocused
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(BrandFont.sans(14, weight: 500))
            .foregroundStyle(foreground)
            .frame(maxWidth: .infinity, minHeight: 38)
            .background(background(pressed: configuration.isPressed), in: .rect(cornerRadius: 12))
            .overlay {
                RoundedRectangle(cornerRadius: 14)
                    .strokeBorder(isFocused ? Theme.focusRing : .clear, lineWidth: 2)
                    .padding(-2)
            }
            .opacity(isEnabled ? 1 : 0.45)
            .contentShape(.rect)
            .scaleEffect(configuration.isPressed && !reduceMotion ? 0.97 : 1)
            .animation(.easeOut(duration: 0.07), value: configuration.isPressed)
    }

    private var foreground: Color {
        switch kind {
        case .secondary: Theme.text
        case .onNewField: Theme.onNewField
        case .primary, .destructive: .white
        }
    }

    private func background(pressed: Bool) -> Color {
        switch kind {
        case .primary: pressed ? Theme.actHover : Theme.act
        case .destructive: Theme.alert
        case .secondary: pressed ? Theme.wellPressed : Theme.well
        case .onNewField: Theme.onNewField.opacity(pressed ? 0.2 : 0.1)
        }
    }
}
