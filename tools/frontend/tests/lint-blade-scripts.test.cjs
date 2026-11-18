const {test}=require('node:test');
const assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const path=require('node:path');
// lint-blade-scripts.cjs is advisory, so a linter that quietly stopped finding anything would look
// exactly like a clean tree. These run it on fixtures with known verdicts, including the 1096e385
// regression (Blade reading Vue's `@error` as its validation directive).
const lint=path.resolve(__dirname,'../lint-blade-scripts.cjs');
const fixture=name=>path.join(__dirname,'fixtures/blade-lint',name);
function run(name){
    const result=spawnSync(process.execPath,[lint,'--json',fixture(name)],{encoding:'utf8'});
    return {status:result.status,findings:JSON.parse(result.stdout).findings};
}

test('flags Vue @error, which Blade compiles as its own directive (commit 1096e385)',()=>{
    const {status,findings}=run('bad-error-directive.blade.php');
    assert.equal(status,1);
    assert.deepEqual(findings.map(f=>[f.rule,f.line]),[['blade-directive-attr',3]]);
    assert.match(findings[0].message,/v-on:error/);
});

test('accepts v-on:error, escaped @@error, commented-out @error and Blade component props',()=>{
    assert.deepEqual(run('good-v-on-error.blade.php'),{status:0,findings:[]});
});

test('accepts Blade-heavy scripts once echoes, @json, @lang and @if are neutralised',()=>{
    assert.deepEqual(run('good-blade-heavy.blade.php'),{status:0,findings:[]});
});

test('reports a <script> body that does not parse',()=>{
    const {status,findings}=run('bad-script-syntax.blade.php');
    assert.equal(status,1);
    assert.deepEqual(findings.map(f=>f.rule),['script-syntax']);
});

test('reports Vue attribute expressions that do not parse',()=>{
    const {status,findings}=run('bad-attr-syntax.blade.php');
    assert.equal(status,1);
    assert.deepEqual(findings.map(f=>f.rule),['attr-syntax','attr-syntax']);
});

test('reports single-quoted Vue attribute expressions that do not parse',()=>{
    assert.deepEqual(run('bad-attr-single-quote.blade.php').findings.map(f=>f.rule),['attr-syntax','attr-syntax']);
});

test('still reads a malformed script between inline @php(...) and a @php block',()=>{
    assert.deepEqual(run('bad-after-inline-php.blade.php').findings.map(f=>f.rule),['script-syntax']);
});

test('accepts module import/export and an @error attribute inside @verbatim',()=>{
    assert.deepEqual(run('good-module-and-verbatim.blade.php'),{status:0,findings:[]});
});

test('rejects a top-level return in a classic script',()=>{
    assert.deepEqual(run('bad-classic-return.blade.php').findings.map(f=>f.rule),['script-syntax']);
});

test('flags the pre-fix Files table from history when it is available',{skip:!process.env.BLADE_LINT_HISTORY},()=>{
    const git=spawnSync('git',['show','1096e385^:packages/Mothership/DAM/src/Resources/views/asset/files/index.blade.php'],{encoding:'utf8',cwd:path.resolve(__dirname,'../../..')});
    assert.equal(git.status,0);
    const {lintSource}=require(lint);
    assert.ok(lintSource('index.blade.php',git.stdout).some(f=>f.rule==='blade-directive-attr'));
});
