import { createHmac, createHash, timingSafeEqual } from 'node:crypto';
import { readFileSync, writeFileSync, renameSync, mkdirSync, openSync, fsyncSync, closeSync } from 'node:fs';
import { dirname } from 'node:path';

export class GrantVerifier {
    constructor({ key, now = () => Math.floor(Date.now() / 1000), replayFile = null }) {
        if (Buffer.byteLength(key ?? '') < 32) throw new Error('shared_key_too_short');
        this.key = key;
        this.now = now;
        this.used = new Map();
        this.replayFile = replayFile;
        if (replayFile) {
            try {
                const entries = JSON.parse(readFileSync(replayFile, 'utf8'));
                if (!Array.isArray(entries) || entries.length > 100000 || !entries.every(entry => Array.isArray(entry)
                    && /^[a-f0-9]{64}$/.test(entry[0]) && Number.isSafeInteger(entry[1]))) throw new Error('invalid_replay_ledger');
                this.used = new Map(entries);
            } catch (error) { if (error.code !== 'ENOENT') throw new Error('replay_ledger_unavailable'); }
        }
    }

    verify({ grant, identity = null }) {
        const fail = () => { throw new Error('invalid_grant'); };
        if (typeof grant !== 'string' || grant.length > 4096) return fail();
        const parts = grant.split('.');
        if (parts.length !== 2 || !parts.every(part => /^[A-Za-z0-9_-]+$/.test(part))) return fail();
        const [payload, signature] = parts;
        const expected = createHmac('sha256', this.key).update(payload).digest();
        const supplied = Buffer.from(signature, 'base64url');
        if (supplied.length !== expected.length || supplied.toString('base64url') !== signature
            || !timingSafeEqual(expected, supplied)) return fail();
        let claims;
        try { claims = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8')); } catch { return fail(); }
        const now = this.now();
        if (!claims || claims.v !== 1 || claims.aud !== 'mothership-transfers'
            || !['sub', 'sid', 'jti', 'name'].every(field => typeof claims[field] === 'string'
                && claims[field].length > 0 && claims[field].length <= 200)
            || !Number.isSafeInteger(claims.iat) || !Number.isSafeInteger(claims.exp)
            || claims.iat > now + 5 || claims.exp <= now || claims.exp <= claims.iat
            || claims.exp - claims.iat > 90
            || (identity && (identity.sub !== claims.sub || identity.sid !== claims.sid))) return fail();
        for (const [id, expiry] of this.used) if (expiry <= now) this.used.delete(id);
        const replayId = createHash('sha256').update(claims.jti).digest('hex');
        if (this.used.has(replayId)) return fail();
        // Never evict a live replay entry to make room for a new grant.
        if (this.used.size >= 100000) return fail();
        this.used.set(replayId, claims.exp);
        if (this.replayFile) {
            // One service process owns this ledger. Commit before exposing authenticated state.
            mkdirSync(dirname(this.replayFile), { recursive: true, mode: 0o700 });
            const temporary = `${this.replayFile}.next`;
            writeFileSync(temporary, JSON.stringify([...this.used]), { mode: 0o600 });
            const descriptor = openSync(temporary, 'r');
            try { fsyncSync(descriptor); } finally { closeSync(descriptor); }
            renameSync(temporary, this.replayFile);
        }
        return claims;
    }
}
