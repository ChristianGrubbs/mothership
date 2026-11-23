import Foundation

/// One line of the progress file written by `stack-action.sh`.
struct ProgressLine: Decodable {
    var step: String
    var state: String
    var rc: Int?
}

public enum ActionStep: String, Sendable, Equatable {
    case waiting, down, up, finishing, clear
    case desktop, prepare, preflight, stopped, build, builder, site, activate, mcp

    public var title: String {
        switch self {
        case .waiting: "Waiting for the container runtime"
        case .clear: "Clearing containers the drive left stuck"
        case .down: "Stopping containers"
        case .up: "Starting containers"
        case .finishing: "Finishing"
        case .desktop: "Updating the ArtCraft apps"
        case .prepare: "Downloading engine updates"
        case .preflight: "Checking nothing else is changing the site"
        case .stopped: "Confirming the site has stopped"
        case .build: "Building engines. The site is off."
        case .builder: "Confirming the builder has stopped"
        case .site: "Confirming the site is back"
        case .activate: "Checking the new engines"
        case .mcp: "Restarting the ArtCraft server"
        }
    }
}

/// Exit code the ArtCraft tool uses when it refuses to restart a server that is in use.
public let craftServerInUseExitCode = 3

public struct ActionProgress: Sendable, Equatable {
    public var step: ActionStep
    public var finished: Bool
    public var exitCode: Int?
    public var failedStep: String?
    public init(step: ActionStep, finished: Bool, exitCode: Int?, failedStep: String?) {
        self.step = step
        self.finished = finished
        self.exitCode = exitCode
        self.failedStep = failedStep
    }
    public var succeeded: Bool { finished && exitCode == 0 }
}

/// Exit code the runner uses when another stack command already holds the stack.
public let actionBusyExitCode = 75

/// Exit code the runner uses when macOS refuses it access to the stack's drive.
public let actionDriveRefusedExitCode = 77

/// Exit codes for the checks around an engine update.
public let actionDeployRunningExitCode = 79
public let actionQueuesBusyExitCode = 80
public let actionBuilderRunningExitCode = 81
public let actionSiteNotBackExitCode = 82

public func parseProgress(_ text: String) -> ActionProgress {
    var progress = ActionProgress(step: .waiting, finished: false, exitCode: nil, failedStep: nil)
    let decoder = JSONDecoder()
    for line in text.split(separator: "\n") {
        guard let parsed = try? decoder.decode(ProgressLine.self, from: Data(line.utf8)) else { continue }
        if parsed.step == "done" {
            progress.finished = true
            progress.exitCode = parsed.rc
            continue
        }
        if let step = ActionStep(rawValue: parsed.step), parsed.state == "start" {
            progress.step = step
        }
        if parsed.state == "end", let rc = parsed.rc, rc != 0 {
            progress.failedStep = parsed.step
        }
    }
    return progress
}

/// Plain-language failure for the panel. `lastLogLine` is shown only if it cannot carry a secret.
public func failureMessage(progress: ActionProgress, lastLogLine: String?) -> String {
    if progress.exitCode == actionBusyExitCode {
        return "Another session is already changing the stack. Try again when it finishes."
    }
    if progress.exitCode == actionDriveRefusedExitCode {
        return "\(driveBlockedMessage) Allow The Mothership in Privacy & Security, then try again."
    }
    if progress.exitCode == actionDeployRunningExitCode {
        return "A deploy may be running, so the site was left as it is. Try again when it finishes."
    }
    if progress.exitCode == actionQueuesBusyExitCode {
        return "The site's queues are busy or couldn't be read, so the site was not stopped. Try again when they finish."
    }
    if progress.exitCode == actionBuilderRunningExitCode {
        return "The site is down and the image builder is still running. Stop the builder before starting the site."
    }
    if progress.exitCode == actionStackAlreadyStoppedExitCode {
        return "The drive dropped off and came back, but every container was already stopped, so the site was left down. Start it if it should be running."
    }
    if progress.exitCode == actionSiteNotBackExitCode {
        return "The site didn't come back, so the app stopped here and won't try again. Check the log."
    }
    if progress.failedStep == "mcp", progress.exitCode == craftServerInUseExitCode {
        return "ArtCraft is in use, so its server kept running."
    }
    let lead: String
    switch progress.failedStep {
    case "waiting": lead = "The container runtime didn't become ready."
    case "clear": lead = "The containers the drive left stuck couldn't all be cleared."
    case "down": lead = "The containers didn't stop cleanly."
    case "up": lead = "The containers didn't start cleanly."
    case "desktop": lead = "Some ArtCraft apps didn't update."
    case "prepare": lead = "The engine updates couldn't be downloaded. The site was not stopped."
    case "stopped": lead = "Containers were still running, so nothing was built. The site was started again."
    case "build": lead = "The engines didn't all build. The site was started again on its existing images."
    case "activate": lead = "Some new engines failed their checks, so the previous versions stay in use."
    case "mcp": lead = "The ArtCraft server didn't restart."
    default: lead = "The stack command stopped unexpectedly."
    }
    guard let lastLogLine, let shown = displayableLogLine(lastLogLine) else { return lead }
    return "\(lead) \(shown)"
}
