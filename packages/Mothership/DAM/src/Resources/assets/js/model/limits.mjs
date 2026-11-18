/**
 * Interactive 3D preview ceilings, shared by the viewer, the 3MF parser and
 * thumbnail capture so the three can never disagree.
 *
 * The download ceiling is 1 GB: large scene GLBs (the campus model passed
 * 256 MB) must open, and a 1 GB ArrayBuffer is well inside every current
 * 64-bit browser's limit. Decoded geometry stays bounded by the triangle
 * ceiling, and expanded 3MF XML by its own 512 MB ceiling (V8's string limit).
 */
export const MAX_MODEL_BYTES = 1024 * 1024 * 1024;

export const MAX_MODEL_LABEL = '1 GB';

export const TOO_LARGE_MESSAGE = `File exceeds the ${MAX_MODEL_LABEL} interactive preview limit`;

/**
 * A touch-primary device (a phone) asks before it downloads a model larger than
 * this: the whole file is held in memory, then parsed, and iOS kills the tab
 * (and Safari reloads it) well below the 1 GB ceiling above.
 */
export const PHONE_MODEL_CONFIRM_BYTES = 50 * 1024 * 1024;
