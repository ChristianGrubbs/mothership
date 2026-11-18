// Fails when the committed front-end bundles are stale. Run it right after
// tools/frontend/build-bundles.sh on a clean checkout: any build file that the
// rebuild changed, added or removed means the committed bundle is not what the
// current sources produce, and that stale bundle is what the Studio serves.
//
// For each stylesheet it also names the classes the rebuilt CSS has and the
// committed CSS lacks — utilities a template uses that do nothing in
// production — with the source files that use them.
const {execFileSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');

const root=path.resolve(__dirname,'../..');
const dam=path.join(root,'packages/Mothership/DAM');
const postcss=require(require.resolve('postcss',{paths:[dam]}));
const selectorParser=require(require.resolve('postcss-selector-parser',{paths:[dam]}));
const git=(...args)=>execFileSync('git',['-C',root,...args],{encoding:'utf8',maxBuffer:1<<28});

const builds={admin:'public/themes/admin/default/build',dam:'public/themes/dam/build'};
const mirror='packages/Mothership/DAM/publishable/dam/build';
// The same places the admin Tailwind config scans.
const sources=['packages/Mothership/*/src/Resources','packages/Mothership/*/Resources'];

// Every stylesheet a manifest lists, read from the committed tree (ref) or the working tree.
function stylesheets(build,ref){
    const read=file=>ref?git('show',`${ref}:${build}/${file}`):fs.readFileSync(path.join(root,build,file),'utf8');
    const files=new Set();
    for(const entry of Object.values(JSON.parse(read('manifest.json')))){
        if(entry.file.endsWith('.css'))files.add(entry.file);
        for(const css of entry.css||[])files.add(css);
    }
    return [...files].map(read);
}

function classNames(css){
    const names=new Set();
    const collect=selectorParser(selectors=>selectors.walkClasses(node=>{names.add(node.value);}));
    for(const text of css)postcss.parse(text).walkRules(rule=>{collect.processSync(rule.selector);});
    return names;
}

function usedIn(name){
    try{
        return git('grep','-l','-F','-e',name,'--',...sources.map(dir=>`:(glob)${dir}/**`)).trim().split('\n').filter(Boolean);
    }catch{
        return [];
    }
}

const changed=git('status','--porcelain','--untracked-files=all','--',...Object.values(builds),mirror).split('\n').filter(Boolean);

if(changed.length===0){
    console.log('Committed admin and DAM bundles match a fresh build.');
    process.exit(0);
}

console.log(`The rebuild changed ${changed.length} committed bundle file(s):`);
for(const line of changed)console.log(`  ${line}`);

for(const [name,build] of Object.entries(builds)){
    const committed=classNames(stylesheets(build,'HEAD')),rebuilt=classNames(stylesheets(build,null));
    const missing=[...rebuilt].filter(className=>!committed.has(className)).sort();
    if(missing.length===0)continue;
    console.log(`\n${missing.length} class(es) used by the sources are missing from the committed ${name} CSS (${build}):`);
    for(const className of missing){
        const files=usedIn(className);
        console.log(`  ${className}${files.length?`  <- ${files.slice(0,3).join(', ')}${files.length>3?`, +${files.length-3} more`:''}`:''}`);
    }
}

console.log('\nRun tools/frontend/build-bundles.sh (Node 22) and commit the result.');
process.exit(1);
