const {test,after}=require('node:test');
const assert=require('node:assert/strict');
const {execFileSync,spawnSync}=require('node:child_process');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
// check-bundles.cjs only ever runs its passing path in CI, so a checker that stopped failing would
// go unnoticed exactly like the stale bundles it guards against. These run it against a throwaway
// repository shaped like this one: committed bundles, a template, and the DAM mirror.
const root=path.resolve(__dirname,'../../..');
const damModules=path.join(root,'packages/Mothership/DAM/node_modules');
const fixtures=[];
after(()=>{for(const dir of fixtures)fs.rmSync(dir,{recursive:true,force:true});});

function fixture(){
    const dir=fs.mkdtempSync(path.join(os.tmpdir(),'check-bundles-'));
    fixtures.push(dir);
    const write=(file,body)=>{fs.mkdirSync(path.dirname(path.join(dir,file)),{recursive:true});fs.writeFileSync(path.join(dir,file),body);};
    // Both ways a Vite manifest names a stylesheet: as a CSS entry's own file (admin here) and in a
    // JavaScript entry's `css` list (DAM here).
    const manifest=file=>JSON.stringify({'src/Resources/assets/css/app.css':{file,src:'src/Resources/assets/css/app.css',isEntry:true}});
    const scriptManifest=css=>JSON.stringify({'src/Resources/assets/js/app.js':{file:'assets/app.js',src:'src/Resources/assets/js/app.js',isEntry:true,css:[css]}});
    fs.mkdirSync(path.join(dir,'tools/frontend'),{recursive:true});
    fs.copyFileSync(path.join(root,'tools/frontend/check-bundles.cjs'),path.join(dir,'tools/frontend/check-bundles.cjs'));
    fs.mkdirSync(path.join(dir,'packages/Mothership/DAM'),{recursive:true});
    fs.symlinkSync(damModules,path.join(dir,'packages/Mothership/DAM/node_modules'));
    write('.gitignore','node_modules\n');
    write('packages/Mothership/DAM/src/Resources/views/folders.blade.php','<div class="flex grid-cols-3"></div>\n');
    write('public/themes/admin/default/build/manifest.json',manifest('assets/app-a.css'));
    write('public/themes/admin/default/build/assets/app-a.css','.flex{display:flex}');
    for(const build of ['public/themes/dam/build','packages/Mothership/DAM/publishable/dam/build']){
        write(`${build}/manifest.json`,scriptManifest('assets/app-b.css'));
        write(`${build}/assets/app.js`,'');
        write(`${build}/assets/app-b.css`,'.flex{display:flex}');
    }
    const git=(...args)=>execFileSync('git',['-C',dir,'-c','user.name=fixture','-c','user.email=fixture@example.test',...args],{encoding:'utf8'});
    git('init','-q');
    git('add','-A');
    git('commit','-qm','fixture');
    return {dir,write,manifest,scriptManifest,check:()=>spawnSync(process.execPath,[path.join(dir,'tools/frontend/check-bundles.cjs')],{encoding:'utf8'})};
}

test('committed bundles that match the rebuild pass',()=>{
    const {check}=fixture();
    const result=check();
    assert.equal(result.status,0,result.stdout+result.stderr);
    assert.match(result.stdout,/match a fresh build/);
});

test('a rebuild that adds a class fails and names the class and the template using it',()=>{
    const {dir,write,manifest,check}=fixture();
    fs.rmSync(path.join(dir,'public/themes/admin/default/build/assets/app-a.css'));
    write('public/themes/admin/default/build/assets/app-c.css','.flex{display:flex}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}');
    write('public/themes/admin/default/build/manifest.json',manifest('assets/app-c.css'));
    const result=check();
    assert.equal(result.status,1,result.stdout+result.stderr);
    assert.match(result.stdout,/public\/themes\/admin\/default\/build\/manifest\.json/);
    assert.match(result.stdout,/1 class\(es\) used by the sources are missing from the committed admin CSS/);
    assert.match(result.stdout,/grid-cols-3 {2}<- packages\/Mothership\/DAM\/src\/Resources\/views\/folders\.blade\.php/);
});

test('a served DAM stylesheet listed in an entry\'s css array is checked and its new class named',()=>{
    const {dir,write,scriptManifest,check}=fixture();
    fs.rmSync(path.join(dir,'public/themes/dam/build/assets/app-b.css'));
    write('public/themes/dam/build/assets/app-d.css','.flex{display:flex}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}');
    write('public/themes/dam/build/manifest.json',scriptManifest('assets/app-d.css'));
    const result=check();
    assert.equal(result.status,1,result.stdout+result.stderr);
    assert.match(result.stdout,/public\/themes\/dam\/build\/manifest\.json/);
    assert.doesNotMatch(result.stdout,/publishable/,'only the served DAM build drifted');
    assert.match(result.stdout,/1 class\(es\) used by the sources are missing from the committed dam CSS/);
    assert.match(result.stdout,/grid-cols-3 {2}<- packages\/Mothership\/DAM\/src\/Resources\/views\/folders\.blade\.php/);
    assert.doesNotMatch(result.stdout,/committed admin CSS/);
});

test('a stale DAM mirror fails even when the served builds match',()=>{
    const {write,check}=fixture();
    write('packages/Mothership/DAM/publishable/dam/build/assets/app-b.css','.flex{display:flex}.block{display:block}');
    const result=check();
    assert.equal(result.status,1,result.stdout+result.stderr);
    assert.match(result.stdout,/packages\/Mothership\/DAM\/publishable\/dam\/build\/assets\/app-b\.css/);
});
