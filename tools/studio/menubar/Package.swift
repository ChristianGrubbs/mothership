// swift-tools-version: 6.2
import PackageDescription

let package = Package(
    name: "MothershipBar",
    platforms: [.macOS("27.0")],
    targets: [
        .target(name: "MothershipBarCore"),
        .executableTarget(name: "MothershipBar", dependencies: ["MothershipBarCore"]),
        .testTarget(name: "MothershipBarCoreTests", dependencies: ["MothershipBarCore"]),
    ]
)
