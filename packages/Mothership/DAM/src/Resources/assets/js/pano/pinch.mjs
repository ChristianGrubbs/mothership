export const PANO_FOV_MIN = 30;
export const PANO_FOV_MAX = 90;

/**
 * Field of view for a two-finger pinch, relative to where the pinch began.
 * Fingers moving apart shrink the view (zoom in); together, widen it. Clamped to the
 * same 30-90 range as the wheel handler.
 */
export function pinchFov({ startFov, startDistance, distance }) {
    if (!(startDistance > 0) || !(distance > 0)) return startFov;

    return Math.max(PANO_FOV_MIN, Math.min(PANO_FOV_MAX, startFov * (startDistance / distance)));
}
