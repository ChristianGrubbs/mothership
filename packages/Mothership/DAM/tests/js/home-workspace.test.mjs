import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Gadget popups (Home workspace) offer Reload and Close tool only — operator directive 2026-09-13.
// home.js is inlined into the Home page, so the contract is checked at the source level.
// Assertions run against code with whole-line comments removed: the comments here explain the
// very controls and dialogs being asserted against, and would otherwise match on their own.
const source = readFileSync(new URL('../../src/Resources/assets/js/home.js', import.meta.url), 'utf8')
    .replace(/^[ \t]*\/\/.*$/gm, '');
const head = source.match(/<div class="agh-workspace-head">[\s\S]*?<\/div><\/div>/)?.[0];
// Everything the popup builds: from the branch that creates a workspace to its keydown trap.
const popup = source.match(/if\(!entry\) \{[\s\S]*?event\.key==='Tab'[\s\S]*?\}\)\;/)?.[0];

test('the workspace head renders Reload and Close tool, and no Collapse control', () => {
    assert.ok(head, 'workspace head markup is present');
    const labels = [...head.matchAll(/<button[^>]*data-tool-([\w-]+)[^>]*>([^<]*)<\/button>/g)].map(match => [match[1], match[2]]);
    assert.deepEqual(labels, [
        ['reload', 'Reload'],
        ['close', 'Close tool'],
        ['confirm-cancel', 'Cancel'],
        ['confirm-accept', 'Discard'],
    ]);
    assert.doesNotMatch(source, /data-tool-collapse/);
    assert.doesNotMatch(source, /Collapse/);
});

test('the confirmation group starts hidden by attribute and inline style', () => {
    // .agh-actions{display:flex} is an author rule, so it beats the UA [hidden] rule on its own;
    // the inline display is what actually keeps the group hidden until it is asked for.
    assert.match(head, /data-tool-confirm hidden style="display:none"/);
});

test('no popup exit depends on window.confirm, which a browser may suppress', () => {
    // Operator report 2026-09-13: Chrome's "prevent additional dialogs" makes confirm() return
    // falsy without asking. With Collapse gone that left Close and Reload dead and Home inert,
    // so the popup had no working exit at all. The confirmation is now in the header.
    assert.ok(popup, 'popup construction block is present');
    assert.doesNotMatch(popup, /(?<![A-Za-z])confirm\(/);
    assert.match(popup, /entry\.askClose=\(\)=>askConfirm\(/);
    // Close tool asks only when work may be lost (the browser suite covers each case).
    assert.match(popup, /\[data-tool-close\]'\)\.onclick=\(\)=>\{if\(!frames\.has\(id\)\)return;if\(mayHoldWork\(entry\)\)entry\.askClose\(\);else entry\.close\(\);\};/);
    assert.match(popup, /\[data-tool-reload\]'\)\.onclick=\(\)=>askConfirm\(/);
});

test('the question has its own strip and never borrows the async status element', () => {
    // Codex review r1 (2026-09-13): the prompt used to snapshot and restore the status element.
    // A load, timeout, preference-error or export writing there while the prompt was up would
    // erase the question, and cancelling would then restore stale text over the newer status.
    assert.match(source, /<div class="agh-frame-status" data-tool-prompt role="alert" hidden><\/div>/);
    assert.match(source, /const message=element\.querySelector\('\.agh-frame-status:not\(\[data-tool-prompt\]\)'\)/);
    const ask = source.match(/const askConfirm=\(\{[\s\S]*?\n\s*\};/)?.[0];
    assert.ok(ask, 'askConfirm is present');
    assert.doesNotMatch(ask, /message/);
    assert.match(ask, /prompt\.textContent=question;prompt\.hidden=false;/);
    const clear = source.match(/const clearConfirm=focusOpener=>\{[\s\S]*?\n\s*\};/)?.[0];
    assert.doesNotMatch(clear, /message/);
    assert.match(clear, /prompt\.hidden=true;/);
});

test('accepting the prompt runs the pending action exactly once, after the prompt is cleared', () => {
    // clearConfirm(true) restores focus to the control that raised the prompt before the action
    // runs: an accepted Reload keeps the popup open, so the focused accept button is about to be
    // hidden and focus would otherwise fall back to the document body. A close moves focus on to
    // the launcher as it tears the workspace down.
    assert.match(popup, /acceptButton\.onclick=\(\)=>\{const action=pending\?\.action;clearConfirm\(true\);action\?\.\(\);\}/);
    assert.match(popup, /if\(pending\)return;/);
});

test('a confirmed close tears the workspace down, renders Home once, then focuses the launcher', () => {
    // Codex review r1 (2026-09-13): hide()+render() followed by a second render() replaced the grid
    // children and destroyed the launcher focus. The close body must contain exactly one render(),
    // after the teardown and before the focus call, and must not go through hide().
    const body = source.match(/entry\.close=\(\)=>\{([\s\S]*?)\n\s*\};/)?.[1];
    assert.ok(body, 'close handler is present');
    assert.equal((body.match(/render\(\)/g) || []).length, 1);
    assert.doesNotMatch(body, /hide\(\)/);
    assert.match(body, /sendActive\(entry,false\);element\.remove\(\);frames\.delete\(id\);sessions\.discard\(id\);root\.closest\('#app'\)\?\.removeAttribute\('inert'\);\s*render\(\);grid\.querySelector\(`\[data-open="\$\{id\}"\]`\)\?\.focus\(\);/);
});

test('a tool that declares it holds no work never arms the discard prompt', () => {
    // PostHog review 2026-11-11: every recorded close of Brand Assets needed a second
    // "Discard and close" click for work that cannot exist. The opt-out is the tool's own ready
    // message, and dirty signals are ignored only for a tool that opted out. Any other tool is
    // asked about once it reports work, or when nothing in it promised to report work.
    // The declaration is held as the document that made it, so a reloaded or replaced document
    // has to declare again (review finding: a per-entry flag outlived the document).
    assert.match(source, /function holdsNoWork\(entry\)\{try\{return Boolean\(entry\.noWorkDocument\)&&entry\.noWorkDocument===entry\.iframe\.contentDocument;\}catch\{return false;\}\}/);
    assert.match(source, /data\.type==='ready'&&typeof data\.payload\?\.holdsWork==='boolean'/);
    assert.match(source, /entry\.noWorkDocument=data\.payload\.holdsWork\?null:entry\.iframe\.contentDocument;/);
    assert.match(source, /const work=\['dirty','media-loaded'\]\.includes\(data\.type\)\|\|\(data\.type==='params'&&!reportsWork\(entry\)\);/);
    assert.match(source, /if\(work&&!holdsNoWork\(entry\)\)sessions\.markDirty\(entry\.id,true\);/);
    assert.match(source, /function mayHoldWork\(entry\)\{return !holdsNoWork\(entry\)&&\(sessions\.isDirty\(entry\.id\)\|\|!reportsWork\(entry\)\);\}/);
    // The bridge makes the promise the host waits for before it closes a tool without asking.
    const bridge = readFileSync(new URL('../../src/Resources/assets/js/home-frame.js', import.meta.url), 'utf8');
    assert.match(bridge, /notify\('ready',\{widget:config\.widget,reportsWork:true\}\);/);
});

test('Escape backs out of the prompt before it ever offers to discard work', () => {
    // A stray Escape inside a tool (dismissing a select or the DAM picker) must not destroy work.
    assert.match(source, /event\.key==='Escape'\)\{event\.preventDefault\(\);if\(!dismissConfirm\(\)\)entry\.askClose\(\);\}/);
    assert.match(source, /data\.type==='escape'&&sessions\.active===entry\.id&&!entry\.dismissConfirm\(\)\)entry\.askClose\(\);/);
    assert.match(source, /querySelector\('\[data-tool-close\]'\)\.focus\(\)/);
});

test('reopening a kept workspace clears any prompt left showing', () => {
    assert.match(source, /entry\.opener=opener\|\|entry\.opener;entry\.clearConfirm\(\);entry\.element\.hidden=false;/);
});

test('the focus trap cycles only the controls that are actually visible', () => {
    assert.match(source, /\.filter\(node=>node\.tagName==='IFRAME'\|\|node\.offsetParent!==null\)/);
});
