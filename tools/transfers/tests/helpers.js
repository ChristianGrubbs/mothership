import { createHmac, randomBytes, randomUUID } from 'node:crypto';
export const freshKey = () => randomBytes(32).toString('hex');
export function grant({ key, sub = 'alice', name = 'Alex Example', sid = 'session-a', ...extra }) {
    const now = Math.floor(Date.now() / 1000);
    const payload = Buffer.from(JSON.stringify({ v: 1, aud: 'mothership-transfers', sub, name, sid,
        jti: randomUUID(), iat: now, exp: now + 60, ...extra })).toString('base64url');
    return `${payload}.${createHmac('sha256', key).update(payload).digest('base64url')}`;
}
export function listen(server) {
    return new Promise(resolve => server.listen(0, '127.0.0.1', () => resolve(`http://127.0.0.1:${server.address().port}`)));
}
