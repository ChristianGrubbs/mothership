import Testing
@testable import MothershipBarCore

@Test func queueReadingSeparatesJobsFromUploads() {
    let text = """
    Some start-up line
    {"idle":false,"counts":{"redis/default":3,"database/stylepress":2,"chunk_uploads_open":1}}

    """
    #expect(parseQueueReading(text) == QueueReading(jobs: 5, uploads: 1))
}

@Test func queueReadingRefusesAnythingItCannotTrust() {
    #expect(parseQueueReading("") == nil)
    #expect(parseQueueReading("PHP Fatal error") == nil)
    #expect(parseQueueReading(#"{"idle":true}"#) == nil)
    #expect(parseQueueReading(#"{"idle":true,"counts":{}}"#) == nil)
    #expect(parseQueueReading(#"{"counts":{"redis/default":"many"}}"#) == nil)
    #expect(parseQueueReading(#"{"counts":{"redis/default":true}}"#) == nil)
    #expect(parseQueueReading(#"{"counts":{"redis/default":-1}}"#) == nil)
    #expect(parseQueueReading(#"{"counts":{"redis/default":1.5}}"#) == nil)
}

@Test func stopWaitsForTheQueueRead() {
    let copy = confirmCopy(for: .stop, mcp: nil, queues: .reading)
    #expect(copy.isWaiting)
    #expect(copy.message.hasSuffix("Checking for running jobs."))
}

@Test func idleQueuesKeepThePlainButton() {
    let copy = confirmCopy(for: .restart, mcp: nil, queues: .read(QueueReading(jobs: 0, uploads: 0)))
    #expect(!copy.isWaiting)
    #expect(copy.button == "Restart Site")
    #expect(!copy.isDestructive)
    #expect(copy.message.hasSuffix("No jobs are waiting or running."))
}

@Test func busyQueuesSayHowMuchWorkIsInterrupted() {
    let both = confirmCopy(for: .stop, mcp: nil, queues: .read(QueueReading(jobs: 12, uploads: 1)))
    #expect(both.message.hasSuffix("12 jobs are waiting or running, and 1 upload is open. Stopping now can interrupt this work."))
    #expect(both.button == "Stop Anyway")
    #expect(both.isDestructive)

    let one = confirmCopy(for: .restart, mcp: nil, queues: .read(QueueReading(jobs: 1, uploads: 0)))
    #expect(one.message.hasSuffix("1 job is waiting or running. Restarting now can interrupt this work."))
    #expect(one.button == "Restart Anyway")
    #expect(one.isDestructive)

    let uploads = confirmCopy(for: .stop, mcp: nil, queues: .read(QueueReading(jobs: 0, uploads: 2)))
    #expect(uploads.message.hasSuffix("2 uploads are open. Stopping now can interrupt this work."))
}

@Test func unreadableQueuesStillLeaveTheChoiceToThePerson() {
    let copy = confirmCopy(for: .stop, mcp: nil, queues: .unreadable)
    #expect(!copy.isWaiting)
    #expect(copy.button == "Stop Anyway")
    #expect(copy.message.contains("couldn't be read"))
}

@Test func siteResultNamesTheActionAndTheTime() {
    #expect(siteResultMessage(for: .start, seconds: 42) == "Site started. It answered after 42 s.")
    #expect(siteResultMessage(for: .restart, seconds: 74) == "Site restarted. It answered after 1 min 14 s.")
    #expect(siteResultMessage(for: .stop, seconds: 20) == nil)
    #expect(siteResultMessage(for: .login, seconds: 20) == nil)
}
