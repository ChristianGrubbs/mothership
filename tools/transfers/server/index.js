import { createTransferService, parentOriginsFromEnv } from './service.js';
let service;
try {
    service = createTransferService({ key: process.env.TRANSFERS_SHARED_KEY,
        origin: process.env.TRANSFERS_ORIGIN, parentOrigins: parentOriginsFromEnv(process.env),
        rtcConfig: JSON.parse(process.env.TRANSFERS_RTC_CONFIG || '{"iceServers":[]}'),
        // The relay is on unless the environment file sets TRANSFERS_RELAY=0 (operator approval 2026-11-11).
        relay: process.env.TRANSFERS_RELAY !== '0', relayAfterMs: Number(process.env.TRANSFERS_RELAY_AFTER_MS || 6000) });
} catch {
    // JSON parser errors can echo a browser-visible TURN credential from configuration.
    process.stderr.write('Transfers startup configuration or replay state is invalid.\n');
    process.exit(1);
}
service.server.listen(Number(process.env.TRANSFERS_PORT || 3001), process.env.TRANSFERS_HOST || '127.0.0.1');
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => { await service.close(); process.exit(0); });
