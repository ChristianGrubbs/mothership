import Foundation
import MothershipBarCore

/// Read-only health checks. Nothing here changes the stack.
struct Probes: Sendable {
    var config: BarConfig

    private static let session: URLSession = {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 6
        configuration.timeoutIntervalForResource = 8
        configuration.waitsForConnectivity = false
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        return URLSession(configuration: configuration)
    }()

    private func get(_ url: URL) async -> (status: Int, data: Data, ms: Int)? {
        let started = Date()
        guard let (data, response) = try? await Self.session.data(from: url),
              let http = response as? HTTPURLResponse else { return nil }
        return (http.statusCode, data, Int(Date().timeIntervalSince(started) * 1000))
    }

    func site() async -> SiteReading {
        async let publicResult = URL(string: config.siteURL).asyncMap(get)
        async let originResult = URL(string: config.originURL).asyncMap(get)
        let (publicReading, origin) = await (publicResult, originResult)
        return SiteReading(publicStatus: publicReading?.status, publicMs: publicReading?.ms, originStatus: origin?.status)
    }

    func containers() async -> ContainerReading? {
        let result = await runCommand(config.wrapperPath, ["status"], timeout: 25)
        guard result.succeeded else {
            BarLog.write("warn", "probe.status_failed", loc: "Probes.containers", ctx: ["exit": "\(result.exitCode)"])
            return nil
        }
        return parseWrapperStatus(result.text)
    }

    /// Asks launchd about the tunnel's job. This launches a process, so it runs on the slower cadence.
    func tunnelJobRunning() async -> Bool {
        let job = await runCommand("/bin/launchctl", ["print", "gui/\(getuid())/\(config.tunnelLabel)"], timeout: 5)
        return job.succeeded && job.text.contains("state = running")
    }

    /// An answer from the tunnel's ready endpoint proves its job is running. Without one, launchd's last word stands.
    func tunnel(lastKnownJobRunning: Bool) async -> TunnelReading {
        guard let url = URL(string: config.tunnelReadyURL), let ready = await get(url),
              let body = try? JSONSerialization.jsonObject(with: ready.data) as? [String: Any] else {
            return TunnelReading(jobRunning: lastKnownJobRunning, readyConnections: nil)
        }
        return TunnelReading(jobRunning: true, readyConnections: (body["readyConnections"] as? NSNumber)?.intValue)
    }

    func bridges() async -> (readings: [BridgeReading]?, targets: [BridgeTarget], driveBlocked: Bool) {
        switch await readFile(config.appEnvPath, timeout: 5) {
        case .blocked: (nil, [], true)
        case .unavailable: (nil, [], false)
        case .text(let text): (await bridgeReadings(appEnv: text), parseBridgeTargets(text), false)
        }
    }

    private func bridgeReadings(appEnv text: String) async -> [BridgeReading] {
        await withTaskGroup(of: (Int, BridgeReading).self) { group in
            for (index, endpoint) in parseBridgeEndpoints(text).enumerated() {
                group.addTask {
                    let answer = await get(endpoint.url)
                    return (index, BridgeReading(
                        name: endpoint.name, ok: bridgeAnswered(status: answer?.status, body: answer?.data),
                        missingModels: bridgeMissingModels(body: answer?.data)
                    ))
                }
            }
            var readings: [(Int, BridgeReading)] = []
            for await reading in group { readings.append(reading) }
            return readings.sorted { $0.0 < $1.0 }.map(\.1)
        }
    }

    func ollama() async -> OllamaReading {
        guard let base = URL(string: config.ollamaURL) else { return OllamaReading(version: nil, loadedModels: 0) }
        async let versionResult = get(base.appending(path: "api/version"))
        async let loadedResult = get(base.appending(path: "api/ps"))
        let (version, loaded) = await (versionResult, loadedResult)
        let versionBody = version.flatMap { try? JSONSerialization.jsonObject(with: $0.data) as? [String: Any] }
        let loadedBody = loaded.flatMap { try? JSONSerialization.jsonObject(with: $0.data) as? [String: Any] }
        return OllamaReading(
            version: versionBody?["version"] as? String,
            loadedModels: (loadedBody?["models"] as? [Any])?.count ?? 0
        )
    }

    func stats(includeContainers: Bool, previous: StatsReading?) async -> StatsReading {
        var reading = StatsReading(
            memoryBytes: previous?.memoryBytes, uptimeSeconds: previous?.uptimeSeconds, diskFreeBytes: nil
        )
        let attributes = try? FileManager.default.attributesOfFileSystem(forPath: config.diskPath)
        reading.diskFreeBytes = (attributes?[.systemFreeSize] as? NSNumber)?.int64Value
        guard includeContainers else { return reading }
        let usage = await runCommand(config.containerBinary, ["stats", "--no-stream", "--format", "json"], timeout: 15)
        reading.memoryBytes = usage.succeeded ? parseStatsMemory(usage.output, containerPrefix: config.containerPrefix) : nil
        reading.uptimeSeconds = await uptime()
        return reading
    }

    private func uptime() async -> Int? {
        let service = await runCommand("/bin/launchctl", ["print", "gui/\(getuid())/\(config.uptimeService)"], timeout: 5)
        guard service.succeeded,
              let line = service.text.split(separator: "\n").first(where: { $0.trimmingCharacters(in: .whitespaces).hasPrefix("pid = ") }),
              let pid = line.split(separator: "=").last?.trimmingCharacters(in: .whitespaces) else { return nil }
        return parseElapsed(await runCommand("/bin/ps", ["-o", "etime=", "-p", pid], timeout: 5).text)
    }

    /// Reads the asset drive and bucket on the Umbrel. Nil means the Umbrel could not be read.
    func umbrelStore() async -> UmbrelStoreReading? {
        guard let arguments = config.umbrelStore?.sshArguments else { return nil }
        let result = await runCommand("/usr/bin/ssh", arguments, timeout: 15)
        guard !result.timedOut, let reading = parseUmbrelStore(result.text) else {
            BarLog.write(
                "warn", "probe.store_failed", loc: "Probes.umbrelStore",
                ctx: ["exit": "\(result.exitCode)", "timed_out": "\(result.timedOut)"]
            )
            return nil
        }
        BarLog.write(
            "info", "probe.store_read", loc: "Probes.umbrelStore",
            ctx: ["free_bytes": "\(reading.driveFreeBytes)", "library_read": "\(reading.libraryBytes != nil)"]
        )
        writeCapacityFile(reading)
        return reading
    }

    /// Replaces the web app's capacity file in one step, so it never reads half a file. Called only
    /// after a good read: on a failed read the old file stays and ages out, which the web app reports.
    private func writeCapacityFile(_ reading: UmbrelStoreReading) {
        guard let path = config.umbrelCapacityFile, path.hasPrefix("/") else { return }
        let temporary = path + ".\(getpid()).tmp"
        let created = FileManager.default.createFile(
            atPath: temporary, contents: umbrelCapacityJSON(reading, checkedAt: Date()),
            attributes: [.posixPermissions: 0o644]
        )
        guard created, rename(temporary, path) == 0 else {
            try? FileManager.default.removeItem(atPath: temporary)
            BarLog.write("warn", "probe.capacity_write_failed", loc: "Probes.writeCapacityFile", ctx: ["errno": "\(errno)"])
            return
        }
    }

    /// Asks the ArtCraft tool what can be updated, and keeps the answer for the next launch.
    func craftCheck() async -> CraftStatus? {
        guard let tool = config.craftTool else { return nil }
        let result = await runCommand(tool.python, [tool.script, "check"], timeout: 120)
        guard result.succeeded, let status = parseCraftStatus(result.text), let line = lastResultLine(result.text) else {
            BarLog.write(
                "warn", "probe.craft_check_failed", loc: "Probes.craftCheck",
                ctx: ["exit": "\(result.exitCode)", "timed_out": "\(result.timedOut)"]
            )
            return nil
        }
        try? line.write(to: BarPaths.craftStatus, atomically: true, encoding: .utf8)
        return status
    }

    /// True while a deploy or another session is running `up` or `down` by hand.
    func externalChange() async -> Bool {
        await runCommand("/usr/bin/pgrep", ["-f", "^[^ ]*[Pp]ython[^ ]* .*runtime\\.py .* (up|down)$"], timeout: 5).succeeded
    }
}

private extension Optional where Wrapped == URL {
    func asyncMap<T: Sendable>(_ transform: (URL) async -> T?) async -> T? {
        guard let self else { return nil }
        return await transform(self)
    }
}
