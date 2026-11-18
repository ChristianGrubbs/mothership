import Foundation

/// Where the asset store lives. The installer writes these values; the read changes nothing on the Umbrel.
public struct UmbrelStoreSource: Codable, Sendable, Equatable {
    public var sshTarget: String
    public var keyPath: String
    public var dataPath: String
    public var container: String
    public var bucket: String

    public init(sshTarget: String, keyPath: String, dataPath: String, container: String, bucket: String) {
        self.sshTarget = sshTarget
        self.keyPath = keyPath
        self.dataPath = dataPath
        self.container = container
        self.bucket = bucket
    }

    private static func matches(_ value: String, _ pattern: String) -> Bool {
        value.range(of: pattern, options: .regularExpression) != nil
    }

    /// The one command run on the Umbrel, or nil when a configured value isn't safe to place in it.
    /// Only the `Size:` line leaves the Umbrel, because the rest of `bucket info` names access keys.
    public var remoteCommand: String? {
        guard Self.matches(dataPath, #"^/[A-Za-z0-9._/-]*$"#),
              Self.matches(container, #"^[A-Za-z0-9][A-Za-z0-9._-]*$"#),
              Self.matches(bucket, #"^[A-Za-z0-9][A-Za-z0-9._-]*$"#) else { return nil }
        return "df -B1 --output=size,used,avail \(dataPath) | tail -n 1; "
            + "timeout 8 docker exec \(container) /garage bucket info \(bucket) 2>/dev/null | grep -E '^Size:'"
    }

    /// Arguments for `/usr/bin/ssh`, or nil when the source can't be used safely.
    public var sshArguments: [String]? {
        guard let remoteCommand, Self.matches(sshTarget, #"^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$"#), keyPath.hasPrefix("/") else {
            return nil
        }
        return [
            "-i", keyPath, "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "ConnectTimeout=6",
            "-o", "StrictHostKeyChecking=yes", "-o", "LogLevel=ERROR", "-T", sshTarget, remoteCommand,
        ]
    }
}

public struct UmbrelStoreReading: Sendable, Equatable {
    public var driveTotalBytes: Int64
    public var driveFreeBytes: Int64
    /// Nil when the drive answered but the storage service did not.
    public var libraryBytes: Int64?

    public init(driveTotalBytes: Int64, driveFreeBytes: Int64, libraryBytes: Int64?) {
        self.driveTotalBytes = driveTotalBytes
        self.driveFreeBytes = driveFreeBytes
        self.libraryBytes = libraryBytes
    }
}

public enum StoreState: Sendable, Equatable {
    case notSetUp, checking, unreachable
    case ready(UmbrelStoreReading)
}

/// Reads the output of `UmbrelStoreSource.remoteCommand`. No drive line means no reading.
public func parseUmbrelStore(_ text: String) -> UmbrelStoreReading? {
    var drive: (total: Int64, free: Int64)?
    var library: Int64?
    for rawLine in text.split(whereSeparator: \.isNewline) {
        let line = rawLine.trimmingCharacters(in: .whitespaces)
        if line.hasPrefix("Size:") {
            library = parseStoreSize(line)
            continue
        }
        let numbers = line.split(whereSeparator: \.isWhitespace).map { Int64($0) }
        guard numbers.count == 3, let total = numbers[0], let free = numbers[2], total > 0, (0...total).contains(free) else {
            continue
        }
        drive = (total, free)
    }
    return drive.map { UmbrelStoreReading(driveTotalBytes: $0.total, driveFreeBytes: $0.free, libraryBytes: library) }
}

/// Reads the decimal figure from a line such as `Size: 190.7 MB (181.9 MiB)`.
private func parseStoreSize(_ line: String) -> Int64? {
    let units: [String: Double] = ["B": 1, "KB": 1e3, "MB": 1e6, "GB": 1e9, "TB": 1e12, "PB": 1e15]
    let parts = line.dropFirst("Size:".count).split(whereSeparator: \.isWhitespace)
    guard parts.count >= 2, let value = Double(parts[0]), value >= 0, let unit = units[parts[1].uppercased()] else { return nil }
    return Int64((value * unit).rounded())
}

/// Seconds between store reads. The drive changes slowly, and each read opens an SSH connection.
/// Four minutes, so the capacity file the web app reads is never more than five minutes old.
public let storeReadInterval: TimeInterval = 240
public let storeReadIntervalWhileOpen: TimeInterval = 60

/// The capacity file the web app reads. `free_bytes` is the drive's available space.
public func umbrelCapacityJSON(_ reading: UmbrelStoreReading, checkedAt: Date) -> Data {
    let stamp = ISO8601DateFormatter().string(from: checkedAt)
    let text = "{\"free_bytes\":\(reading.driveFreeBytes),\"total_bytes\":\(reading.driveTotalBytes),\"checked_at\":\"\(stamp)\"}\n"
    return Data(text.utf8)
}

public func storeReadIsDue(lastRead: Date?, lastSucceeded: Bool, panelIsOpen: Bool, now: Date) -> Bool {
    guard let lastRead else { return true }
    let interval = panelIsOpen || !lastSucceeded ? storeReadIntervalWhileOpen : storeReadInterval
    return now.timeIntervalSince(lastRead) >= interval
}
