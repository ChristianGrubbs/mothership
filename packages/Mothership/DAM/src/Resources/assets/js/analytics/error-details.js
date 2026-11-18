const ERROR_TYPES = new Set([
    "Error", "TypeError", "RangeError", "ReferenceError", "SyntaxError", "URIError", "EvalError", "AggregateError",
    "DOMException", "AbortError", "NetworkError", "InvalidStateError", "NotAllowedError", "SecurityError",
    "DataError", "QuotaExceededError", "NotSupportedError", "TimeoutError",
]);

const frameMatch = text => text.match(/^\s+at\s+(?:async\s+)?(https?:\/\/[^\s()]+):(\d+):(\d+)$/)
    || text.match(/^\s+at\s+[^()\n]+\s+\((https?:\/\/[^\s()]+):(\d+):(\d+)\)$/)
    || text.match(/^[^@:\n]*@(https?:\/\/[^\s()]+):(\d+):(\d+)$/);

// Only same-origin static bundles or the current document identify source.
// Document paths, asset names, arbitrary URLs and raw text never leave this adapter.
const sourceFrame = ({ source, origin, pathname, line, column }) => {
    if (typeof source !== "string" || source.length === 0 || source.length > 1024) return {};
    const url = new URL(source, origin);
    if (url.origin !== origin || url.username || url.password) return {};
    const match = url.pathname.match(/^\/themes\/(?:dam|admin\/default)\/build\/assets\/([A-Za-z0-9_.-]{1,32}-[A-Za-z0-9_-]{8}\.js)$/);
    const frame = match ? { source_kind: "bundle", bundle: match[1] }
        : pathname && url.pathname === pathname ? { source_kind: "document" } : null;
    if (!frame) return {};
    for (const [key, value] of Object.entries({ line, column })) {
        if (Number.isInteger(value) && value > 0 && value <= 1e9) frame[key] = value;
    }
    return frame;
};

export function errorDetails({ event, kind, origin, pathname }) {
    const details = {};
    try {
        const error = kind === "rejection" ? event.reason : event.error;
        let name;
        if (error && (typeof error === "object" || typeof error === "function")) {
            name = error.name;
            if (ERROR_TYPES.has(name)) details.error_type = name;
        }
        if (kind === "error") {
            Object.assign(details, sourceFrame({ source: event.filename, origin, pathname, line: event.lineno, column: event.colno }));
        }
        // A rejection has no ErrorEvent filename. Inspect a bounded stack only
        // to find the first accepted static frame, then discard the source text.
        if (kind === "rejection" && error && typeof error === "object") {
            const stack = error.stack;
            if (typeof stack === "string" && stack.length <= 16384) {
                let frames = stack;
                // V8 puts the complete (possibly multiline) message before its
                // frames. Remove that exact prefix in memory; never parse it as
                // coordinates, even when its text imitates a stack-frame line.
                const message = error.message;
                const header = typeof name === "string" && name && typeof message === "string"
                    ? name + (message ? ": " + message : "") : null;
                if (header !== null && (stack === header || stack.startsWith(header + "\n"))) {
                    frames = stack.slice(header.length);
                } else {
                    const firstLine = stack.split("\n")[0];
                    // Gecko/WebKit start with a frame, not the message. If a
                    // non-stripped message overlaps that line, its coordinates
                    // cannot safely be attributed even when it resembles a frame.
                    if (typeof message === "string" && message && firstLine.includes(message.split("\n")[0])) return details;
                    if (!frameMatch(firstLine)) return details;
                }
                for (const text of frames.split("\n").slice(0, 16)) {
                    const match = frameMatch(text);
                    if (!match) continue;
                    const frame = sourceFrame({ source: match[1], origin, pathname, line: Number(match[2]), column: Number(match[3]) });
                    if (frame.source_kind) {
                        Object.assign(details, frame);
                        break;
                    }
                }
            }
        }
    } catch (_) {
        // Malformed data/getters must not recurse through the global listener.
    }
    return details;
}
