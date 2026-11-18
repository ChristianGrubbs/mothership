import AppKit

let arguments = CommandLine.arguments

if let flag = arguments.firstIndex(of: "--render-states"), arguments.indices.contains(flag + 1) {
    let application = NSApplication.shared
    application.setActivationPolicy(.prohibited)
    do {
        try MainActor.assumeIsolated {
            let only = arguments.indices.contains(flag + 2) ? arguments[flag + 2].split(separator: ",").map(String.init) : []
            try RenderStates.run(directory: URL(fileURLWithPath: arguments[flag + 1]), only: only)
        }
        exit(0)
    } catch {
        FileHandle.standardError.write(Data("render failed: \(error)\n".utf8))
        exit(1)
    }
}

// Reads the live stack once, changes nothing, and writes the panel as it stands to PNG.
if let flag = arguments.firstIndex(of: "--render-live"), arguments.indices.contains(flag + 1) {
    NSApplication.shared.setActivationPolicy(.prohibited)
    let directory = URL(fileURLWithPath: arguments[flag + 1])
    Task { @MainActor in
        do {
            try await RenderStates.runLive(directory: directory)
            exit(0)
        } catch {
            FileHandle.standardError.write(Data("live render failed: \(error)\n".utf8))
            exit(1)
        }
    }
    RunLoop.main.run()
}

// The installer calls this so that installing the app never counts as a login and never starts the stack.
if arguments.contains("--mark-login-handled") {
    exit(LoginSession.markHandled() ? 0 : 1)
}

let application = NSApplication.shared
let delegate = MainActor.assumeIsolated { AppDelegate(launchedAtLogin: arguments.contains("--login")) }
application.delegate = delegate
application.setActivationPolicy(.accessory)
application.run()
