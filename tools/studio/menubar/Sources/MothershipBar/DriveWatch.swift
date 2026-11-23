import DiskArbitration
import Foundation

/// Reports when the volume that holds the stack goes away, however briefly. A USB reset can drop the drive for
/// under a second, which polling misses, but Disk Arbitration still reports the disk disappearing or unmounting.
/// The containers that had files open on the old mount stay broken after it comes back, so the app needs to know.
@MainActor
final class DriveWatch {
    private let path: String
    private let volumeUUID: String
    private let session: DASession
    private let onDrop: @MainActor () -> Void

    /// Nil when the path isn't on a mounted volume with a UUID, or Disk Arbitration can't be reached.
    init?(path: String, onDrop: @escaping @MainActor () -> Void) {
        guard let uuid = Self.volumeUUID(at: path), let session = DASessionCreate(kCFAllocatorDefault) else { return nil }
        self.path = path
        self.volumeUUID = uuid
        self.session = session
        self.onDrop = onDrop
        let context = Unmanaged.passUnretained(self).toOpaque()
        DARegisterDiskDisappearedCallback(session, nil, { disk, context in
            DriveWatch.deliver(disk: disk, context: context, event: "disappeared")
        }, context)
        let watchedKeys = [kDADiskDescriptionVolumePathKey] as CFArray
        DARegisterDiskDescriptionChangedCallback(session, nil, watchedKeys, { disk, _, context in
            // A volume path that goes away means the volume was unmounted, even if the disk itself stays.
            guard DriveWatch.description(of: disk)[kDADiskDescriptionVolumePathKey as String] == nil else { return }
            DriveWatch.deliver(disk: disk, context: context, event: "unmounted")
        }, context)
        DASessionSetDispatchQueue(session, DispatchQueue.main)
        BarLog.write("info", "drive.watch_started", loc: "DriveWatch.init", ctx: ["path": path])
    }

    /// True while the watched volume is mounted at its path again.
    func isMounted() -> Bool {
        Self.volumeUUID(at: path) == volumeUUID
    }

    /// The UUID of the volume mounted exactly at `path`. Nil when nothing is mounted there, so an empty
    /// mount-point folder left behind on the startup disk is never mistaken for the drive.
    private static func volumeUUID(at path: String) -> String? {
        let url = URL(fileURLWithPath: path)
        let values = try? url.resourceValues(forKeys: [.volumeUUIDStringKey, .volumeURLKey])
        guard let volume = values?.volume, volume.standardizedFileURL.path == url.standardizedFileURL.path else { return nil }
        return values?.volumeUUIDString?.uppercased()
    }

    private nonisolated static func description(of disk: DADisk) -> [String: Any] {
        (DADiskCopyDescription(disk) as? [String: Any]) ?? [:]
    }

    /// Disk Arbitration calls back on the main queue, which `DASessionSetDispatchQueue` set above.
    private nonisolated static func deliver(disk: DADisk, context: UnsafeMutableRawPointer?, event: String) {
        guard let context else { return }
        let value = description(of: disk)[kDADiskDescriptionVolumeUUIDKey as String]
        guard let value, CFGetTypeID(value as CFTypeRef) == CFUUIDGetTypeID() else { return }
        let uuid = (CFUUIDCreateString(kCFAllocatorDefault, (value as! CFUUID)) as String).uppercased()
        // The watch lives as long as the app, so its address stays valid for every callback.
        let address = UInt(bitPattern: context)
        MainActor.assumeIsolated {
            guard let pointer = UnsafeMutableRawPointer(bitPattern: address) else { return }
            let watch = Unmanaged<DriveWatch>.fromOpaque(pointer).takeUnretainedValue()
            guard uuid == watch.volumeUUID else { return }
            BarLog.write("warn", "drive.dropped", loc: "DriveWatch.deliver", ctx: ["event": event])
            watch.onDrop()
        }
    }
}
