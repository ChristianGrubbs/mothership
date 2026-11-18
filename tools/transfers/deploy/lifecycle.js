#!/usr/bin/env node
// Planning is the default. Only --apply mutates launchd or files. No environment values are printed.
import { existsSync, statSync, realpathSync, readFileSync, writeFileSync, mkdirSync, renameSync, unlinkSync, appendFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { isAbsolute, join, dirname, relative } from 'node:path';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
export const LABEL = 'app.themothership.transfers';
const xml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&apos;');
export function parseArguments(argv) {
    const options = { apply: false, domain: `gui/${process.getuid?.() ?? 0}` };
    const names = new Set(['release', 'node', 'env-file', 'state-dir', 'plist', 'domain']);
    for (let index = 0; index < argv.length; index++) {
        const argument = argv[index];
        if (argument === '--apply') { if (options.apply) throw new Error('duplicate_apply'); options.apply = true; continue; }
        const name = argument.slice(2);
        if (!argument.startsWith('--') || !names.has(name) || !argv[index + 1] || argv[index + 1].startsWith('--')) throw new Error('invalid_arguments');
        if (Object.hasOwn(options, name) && name !== 'domain') throw new Error('duplicate_argument');
        options[name] = argv[++index];
    }
    for (const name of ['release', 'node', 'env-file', 'state-dir', 'plist']) {
        if (!options[name] || !isAbsolute(options[name]) || /[\r\n\0]/.test(options[name])) throw new Error(`absolute_${name}_required`);
    }
    if (!/^gui\/\d+$/.test(options.domain)) throw new Error('gui_domain_required');
    return options;
}
export function makePlan(options) {
    const release = realpathSync(options.release);
    const node = realpathSync(options.node);
    const envFile = realpathSync(options['env-file']);
    const entry = join(release, 'server/index.js');
    if (!statSync(entry).isFile()) throw new Error('release_entry_missing');
    const envStat = statSync(envFile);
    if (!envStat.isFile() || (envStat.mode & 0o777) !== 0o600 || envStat.uid !== (process.getuid?.() ?? 0)) throw new Error('env_file_requires_owner_mode_0600');
    if (!(statSync(node).mode & 0o111)) throw new Error('node_not_executable');
    const version = spawnSync(node, ['--version'], { encoding: 'utf8', timeout: 5000 });
    const major = /^v(\d+)\./.exec(version.stdout.trim())?.[1];
    if (version.status !== 0 || !major || Number(major) < 22) throw new Error('node22_or_newer_required');
    // Resolve existing ancestors too, so symlinks cannot hide release-local state.
    function canonicalTarget(path) {
        if (existsSync(path)) return realpathSync(path);
        return join(canonicalTarget(dirname(path)), path.slice(dirname(path).length + 1));
    }
    const stateDir = canonicalTarget(options['state-dir']);
    const plistPath = canonicalTarget(options.plist);
    for (const path of [stateDir, envFile, plistPath]) {
        const rel = relative(release, path);
        if (rel === '' || (!rel.startsWith('..' + '/') && rel !== '..' && !isAbsolute(rel))) throw new Error('runtime_path_inside_release');
    }
    if (!plistPath.endsWith(`/${LABEL}.plist`)) throw new Error('dedicated_plist_required');
    const argumentsList = [node, `--env-file=${envFile}`, entry];
    const plist = `<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">\n<plist version="1.0"><dict>
<key>Label</key><string>${LABEL}</string>
<key>ProgramArguments</key><array>${argumentsList.map(value => `<string>${xml(value)}</string>`).join('')}</array>
<key>WorkingDirectory</key><string>${xml(release)}</string>
<key>EnvironmentVariables</key><dict><key>TRANSFERS_STATE_DIR</key><string>${xml(stateDir)}</string></dict>
<key>RunAtLoad</key><true/><key>KeepAlive</key><true/><key>ThrottleInterval</key><integer>10</integer>
<key>StandardOutPath</key><string>${xml(join(stateDir, 'logs/stdout.log'))}</string>
<key>StandardErrorPath</key><string>${xml(join(stateDir, 'logs/stderr.log'))}</string>
<key>Umask</key><integer>63</integer>
</dict></plist>\n`;
    return { label: LABEL, domain: options.domain, release, node, envFile, stateDir, plistPath,
        action: options.apply ? 'apply' : 'dry-run', plist };
}
export function applyPlan(plan) {
    if (process.platform !== 'darwin') throw new Error('macos_required');
    if (plan.action !== 'apply') throw new Error('explicit_apply_required');
    const launchctl = args => spawnSync('/bin/launchctl', args, { encoding: 'utf8', timeout: 15000 });
    const target = `${plan.domain}/${LABEL}`;
    const runId = randomUUID();
    const audit = event => {
        if (process.env.TRANSFERS_LOGGING !== '1') return;
        appendFileSync(join(plan.stateDir, 'logs/lifecycle.jsonl'), JSON.stringify({ ts: new Date().toISOString(),
            run_id: runId, level: 'info', event, loc: 'transfers/deploy/lifecycle', ctx: { apply: true } }) + '\n', { mode: 0o600 });
    };
    const previous = existsSync(plan.plistPath) ? readFileSync(plan.plistPath) : null;
    const running = launchctl(['print', target]).status === 0;
    if (running && !previous) throw new Error('existing_service_without_rollback_plist');
    mkdirSync(join(plan.stateDir, 'logs'), { recursive: true, mode: 0o700 });
    audit('apply_started');
    mkdirSync(dirname(plan.plistPath), { recursive: true });
    const temporary = `${plan.plistPath}.next`;
    writeFileSync(temporary, plan.plist, { mode: 0o600 });
    if (spawnSync('/usr/bin/plutil', ['-lint', temporary], { timeout: 5000 }).status !== 0) { unlinkSync(temporary); throw new Error('plist_validation_failed'); }
    if (running && launchctl(['bootout', target]).status !== 0) { unlinkSync(temporary); throw new Error('bootout_failed'); }
    renameSync(temporary, plan.plistPath);
    if (launchctl(['bootstrap', plan.domain, plan.plistPath]).status !== 0) {
        if (previous) writeFileSync(plan.plistPath, previous, { mode: 0o600 });
        else unlinkSync(plan.plistPath);
        if (running && launchctl(['bootstrap', plan.domain, plan.plistPath]).status !== 0) throw new Error('bootstrap_failed_rollback_failed');
        audit('bootstrap_failed');
        throw new Error('bootstrap_failed_previous_plist_restored');
    }
    audit('bootstrap_succeeded');
    return { applied: true, label: LABEL, domain: plan.domain };
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const plan = makePlan(parseArguments(process.argv.slice(2)));
        if (plan.action === 'apply') process.stdout.write(JSON.stringify(applyPlan(plan)) + '\n');
        else { const { plist, ...summary } = plan; process.stdout.write(JSON.stringify(summary, null, 2) + '\n'); }
    } catch (error) {
        // OS error messages may include environment-file contents only in child output; never echo child output.
        process.stderr.write(`transfers lifecycle failed: ${error.code || error.message}\n`); process.exitCode = 1;
    }
}
