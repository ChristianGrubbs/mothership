import { fileURLToPath } from 'node:url';
import { isAbsolute, join } from 'node:path';
export function stateDirectory() {
    const directory = process.env.TRANSFERS_STATE_DIR || fileURLToPath(new URL('../storage/', import.meta.url));
    if (!isAbsolute(directory)) throw new Error('state_directory_must_be_absolute');
    return directory;
}
export const replayPath = () => join(stateDirectory(), 'grant-replay.json');
