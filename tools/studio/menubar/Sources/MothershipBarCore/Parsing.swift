import Foundation

/// Reads the wrapper's `status` output. Only `role: state ...` lines for containers count.
public func parseWrapperStatus(_ text: String) -> ContainerReading {
    let ignored: Set<String> = ["web_transport", "web_relay", "collector"]
    var running = 0
    var total = 0
    var notRunning: [String] = []
    for line in text.split(separator: "\n") {
        let parts = line.split(separator: ":", maxSplits: 1)
        guard parts.count == 2 else { continue }
        let role = String(parts[0])
        guard !ignored.contains(role), role.allSatisfy({ $0.isLowercase || $0 == "_" }) else { continue }
        guard let state = parts[1].split(separator: " ").first else { continue }
        total += 1
        if state == "running" {
            running += 1
        } else {
            notRunning.append(role)
        }
    }
    return ContainerReading(running: running, total: total, notRunning: notRunning)
}

/// Sums memory for this stack's containers from `container stats --format json`.
public func parseStatsMemory(_ data: Data, containerPrefix: String) -> UInt64? {
    guard let rows = try? JSONSerialization.jsonObject(with: data) as? [[String: Any]] else { return nil }
    let matching = rows.filter { ($0["id"] as? String)?.hasPrefix(containerPrefix) == true }
    guard !matching.isEmpty else { return nil }
    return matching.reduce(UInt64(0)) { $0 + (($1["memoryUsageBytes"] as? NSNumber)?.uint64Value ?? 0) }
}

/// Reads `ps -o etime=` output: `[[dd-]hh:]mm:ss`.
public func parseElapsed(_ text: String) -> Int? {
    let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !trimmed.isEmpty else { return nil }
    let dayParts = trimmed.split(separator: "-")
    guard dayParts.count <= 2, let clock = dayParts.last else { return nil }
    var days = 0
    if dayParts.count == 2 {
        guard let parsed = Int(dayParts[0]) else { return nil }
        days = parsed
    }
    let fields = clock.split(separator: ":").map { Int($0) }
    guard (2...3).contains(fields.count), fields.allSatisfy({ $0 != nil }) else { return nil }
    let values = fields.compactMap { $0 }
    let seconds = values.reduce(0) { $0 * 60 + $1 }
    return days * 86_400 + seconds
}

/// Bridge endpoints from `app.env`. Reads the three named keys and nothing else from that file.
public func parseBridgeEndpoints(_ text: String) -> [(name: String, url: URL)] {
    let wanted = [
        ("MOTHERSHIP_OPTIMIZER_API", "Optimizer"),
        ("MOTHERSHIP_AI_API", "AI"),
        ("MOTHERSHIP_HOME_VIDEO_API", "Video"),
    ]
    var values: [String: String] = [:]
    for rawLine in text.split(separator: "\n") {
        var line = rawLine.trimmingCharacters(in: .whitespaces)
        if line.hasPrefix("export ") { line = String(line.dropFirst(7)).trimmingCharacters(in: .whitespaces) }
        guard let equals = line.firstIndex(of: "=") else { continue }
        let key = line[..<equals].trimmingCharacters(in: .whitespaces)
        guard wanted.contains(where: { $0.0 == key }) else { continue }
        var value = String(line[line.index(after: equals)...]).trimmingCharacters(in: .whitespaces)
        if value.count >= 2, let first = value.first, first == value.last, first == "\"" || first == "'" {
            value = String(value.dropFirst().dropLast())
        }
        values[key] = value
    }
    return wanted.compactMap { key, name in
        guard let value = values[key], !value.isEmpty, let url = URL(string: value), url.scheme == "http" else { return nil }
        return (name, url.appending(path: "health"))
    }
}

/// Every service name the runtime reports when a readiness wait fails.
private let knownServices = [
    "postgres peer route", "elasticsearch peer route", "mailpit peer route", "looper peer route",
    "web relay", "postgres", "redis", "elasticsearch", "mailpit", "looper", "fpm", "queue",
    "panorama_queue", "stylepress_queue", "vectorization_queue", "ai_index_queue", "scheduler", "nginx",
]

/// A wrapper log line fit for the panel, or nil. The message is rebuilt from a known service name and
/// a number, because no filter can prove arbitrary command output is free of secrets. The full text
/// stays in the log.
public func displayableLogLine(_ line: String) -> String? {
    let trimmed = line.trimmingCharacters(in: .whitespacesAndNewlines)
    if trimmed.range(of: #"^container runtime not ready after [0-9]{1,5} seconds$"#, options: .regularExpression) != nil {
        return trimmed
    }
    let services = knownServices.joined(separator: "|")
    let pattern = "(?:^|: )(\(services)) did not become ready (in [0-9]{1,5}s|within [0-9]{1,5} seconds)(?=:|$)"
    guard let regex = try? NSRegularExpression(pattern: pattern),
          let match = regex.firstMatch(in: trimmed, range: NSRange(trimmed.startIndex..., in: trimmed)),
          let service = Range(match.range(at: 1), in: trimmed),
          let wait = Range(match.range(at: 2), in: trimmed)
    else { return nil }
    return "\(trimmed[service]) did not become ready \(trimmed[wait])"
}

public enum Format {
    public static func milliseconds(_ value: Int?) -> String {
        guard let value else { return "–" }
        return value >= 1000 ? String(format: "%.1f s", Double(value) / 1000) : "\(value) ms"
    }

    public static func bytes(_ value: Int64?) -> String {
        guard let value else { return "–" }
        let gigabytes = Double(value) / 1_000_000_000
        if gigabytes < 0.99995 {
            let megabytes = Double(value) / 1_000_000
            return megabytes >= 100 ? String(format: "%.1f MB", megabytes) : String(format: "%.2g MB", megabytes)
        }
        if gigabytes >= 999.5 { return String(format: "%.1f TB", gigabytes / 1000) }
        return gigabytes >= 100 ? String(format: "%.0f GB", gigabytes) : String(format: "%.1f GB", gigabytes)
    }

    public static func uptime(_ seconds: Int?) -> String {
        guard let seconds else { return "–" }
        let days = seconds / 86_400
        let hours = seconds % 86_400 / 3600
        let minutes = seconds % 3600 / 60
        if days > 0 { return "\(days)d \(hours)h" }
        if hours > 0 { return "\(hours)h \(minutes)m" }
        return "\(max(minutes, 1))m"
    }

    public static func clock(_ seconds: Int) -> String {
        String(format: "%d:%02d", seconds / 60, seconds % 60)
    }
}
