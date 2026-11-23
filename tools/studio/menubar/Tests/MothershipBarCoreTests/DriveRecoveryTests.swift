import Foundation
import Testing
@testable import MothershipBarCore

private let dropped = Date(timeIntervalSince1970: 1_000)

private func backAndSettled(_ recovery: inout DriveRecovery) -> Date {
    recovery.observe(driveReadable: true, at: dropped.addingTimeInterval(5))
    return dropped.addingTimeInterval(5 + DriveRecovery.settle)
}

@Test func aDropWhileTheStackRanLeadsToOneRecoveryOnceTheDriveSettles() {
    var recovery = DriveRecovery()
    let recorded = recovery.driveDropped(stackWasRunning: true, at: dropped)
    #expect(recorded)
    recovery.observe(driveReadable: true, at: dropped.addingTimeInterval(5))
    #expect(recovery.next(clear: true, stackHealthy: false, now: dropped.addingTimeInterval(10)) == .wait, "still settling")
    #expect(recovery.next(clear: true, stackHealthy: false, now: dropped.addingTimeInterval(25)) == .recover)
    recovery.reset()
    #expect(recovery.next(clear: true, stackHealthy: false, now: dropped.addingTimeInterval(60)) == .wait, "never twice")
}

@Test func aStackThatOnlyLooksDownIsNeverRestarted() {
    let recovery = DriveRecovery()
    #expect(recovery.next(clear: true, stackHealthy: false, now: dropped) == .wait)
}

@Test func aDropWhileTheStackWasStoppedIsIgnored() {
    var recovery = DriveRecovery()
    let recorded = recovery.driveDropped(stackWasRunning: false, at: dropped)
    #expect(!recorded)
    let later = backAndSettled(&recovery)
    #expect(recovery.next(clear: true, stackHealthy: false, now: later) == .wait)
}

@Test func nothingStartsWhileSomethingElseHoldsTheStack() {
    var recovery = DriveRecovery()
    _ = recovery.driveDropped(stackWasRunning: true, at: dropped)
    let later = backAndSettled(&recovery)
    #expect(recovery.next(clear: false, stackHealthy: false, now: later) == .wait)
    #expect(recovery.next(clear: true, stackHealthy: false, now: later) == .recover, "the drop is kept until it is clear")
}

@Test func theSettleClockStartsAgainWhenTheDriveGoesAgain() {
    var recovery = DriveRecovery()
    _ = recovery.driveDropped(stackWasRunning: true, at: dropped)
    recovery.observe(driveReadable: true, at: dropped.addingTimeInterval(5))
    recovery.observe(driveReadable: false, at: dropped.addingTimeInterval(15))
    recovery.observe(driveReadable: true, at: dropped.addingTimeInterval(20))
    #expect(recovery.next(clear: true, stackHealthy: false, now: dropped.addingTimeInterval(30)) == .wait)
    #expect(recovery.next(clear: true, stackHealthy: false, now: dropped.addingTimeInterval(40)) == .recover)
}

@Test func aSecondDropKeepsTheFirstTime() {
    var recovery = DriveRecovery()
    _ = recovery.driveDropped(stackWasRunning: true, at: dropped)
    let recordedAgain = recovery.driveDropped(stackWasRunning: false, at: dropped.addingTimeInterval(3))
    #expect(!recordedAgain)
    #expect(recovery.droppedAt == dropped)
}

@Test func aHealthyStackIsLeftAloneAndTheDropForgotten() {
    var recovery = DriveRecovery()
    _ = recovery.driveDropped(stackWasRunning: true, at: dropped)
    let later = backAndSettled(&recovery)
    #expect(recovery.next(clear: true, stackHealthy: true, now: later) == .discard)
}

@Test func aDropOlderThanAnHourIsForgottenNotActedOn() {
    var recovery = DriveRecovery(droppedAt: dropped)
    recovery.observe(driveReadable: true, at: dropped.addingTimeInterval(DriveRecovery.maxAge))
    let later = dropped.addingTimeInterval(DriveRecovery.maxAge + DriveRecovery.settle)
    #expect(recovery.next(clear: true, stackHealthy: false, now: later) == .discard)
    #expect(recovery.next(clear: false, stackHealthy: false, now: later) == .discard, "even while the stack is busy")
}

@Test func aStoredDropStillRecoversAfterTheAppRestarts() {
    var recovery = DriveRecovery(droppedAt: dropped)
    let later = backAndSettled(&recovery)
    #expect(recovery.next(clear: true, stackHealthy: false, now: later) == .recover)
}

@Test func aRecoveryThatFindsTheStackStoppedSaysItLeftTheSiteDown() {
    let progress = parseProgress(#"""
    {"step":"clear","state":"start"}
    {"step":"clear","state":"end","rc":86}
    {"step":"done","state":"end","rc":86}
    """#)
    #expect(progress.exitCode == actionStackAlreadyStoppedExitCode)
    #expect(!progress.succeeded)
    #expect(failureMessage(progress: progress, lastLogLine: nil).contains("the site was left down"))
}
