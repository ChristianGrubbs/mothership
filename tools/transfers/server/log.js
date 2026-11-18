import { appendFileSync, mkdirSync, existsSync, statSync, renameSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { join } from 'node:path';
import { stateDirectory } from './state.js';
const runId = randomUUID();
export function connectionDiagnostic(message) {
    const fields = {
        source: ['connected', 'connecting-timeout', 'sdp', 'ice-add', 'ice-candidate', 'signaling-or-channel', 'data-channel-closed', 'connection-timeout', 'connection-state', 'ice-state', 'data-channel'],
        connection: ['new', 'connecting', 'connected', 'disconnected', 'failed', 'closed'],
        ice: ['new', 'checking', 'connected', 'completed', 'disconnected', 'failed', 'closed'],
        signaling: ['stable', 'have-local-offer', 'have-remote-offer', 'have-local-pranswer', 'have-remote-pranswer', 'closed'],
        gathering: ['new', 'gathering', 'complete']
    };
    const result = {};
    for (const [key, values] of Object.entries(fields)) {
        if (!values.includes(message[key])) return null;
        result[key] = message[key];
    }
    for (const key of ['code', 'localCandidates', 'remoteCandidates']) {
        if (!Number.isInteger(message[key]) || message[key] < 0 || message[key] > 999) return null;
        result[key] = message[key];
    }
    return result;
}
// `detail` carries relay ids and counters set by the service itself; it never holds client-supplied text.
export function log({ event, count = 0, diagnostic, detail }) {
    if (process.env.TRANSFERS_LOGGING !== '1') return;
    try {
        const directory = join(stateDirectory(), 'logs');
        mkdirSync(directory, { recursive: true });
        const path = join(directory, 'transfers.jsonl');
        // Keep only the current file and one previous file; logging is single-process.
        if (existsSync(path) && statSync(path).size >= 5 * 1024 * 1024) renameSync(path, `${path}.1`);
        appendFileSync(path, JSON.stringify({ ts: new Date().toISOString(),
            run_id: runId, level: 'info', event, loc: 'transfers/server', ctx: { count, ...(diagnostic || {}), ...(detail || {}) } }) + '\n');
    } catch { /* logging must not interrupt cleanup */ }
}
