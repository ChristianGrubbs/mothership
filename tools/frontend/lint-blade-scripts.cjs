// Advisory lint for JavaScript embedded in Blade views (default: the DAM views).
//
// The DAM keeps most of its Vue/JS inside <script> blocks in .blade.php files, where no ordinary
// JS tooling looks. Two defect classes are caught here without any dependency (node:vm only):
//
//   blade-directive-attr  A Vue event shorthand that Blade also owns, such as `@error="..."`.
//                         Blade compiles `@error` as its validation directive, so the attribute
//                         never reaches the browser (commit 1096e385; fix: `v-on:error`).
//   script-syntax         A <script> body that does not parse after Blade tokens are neutralised.
//   attr-syntax           A Vue attribute expression (`:x`, `v-if`, `@evt`, ...) that does not parse.
//
// Usage: node tools/frontend/lint-blade-scripts.cjs [--json] [file-or-dir ...]
// Exit 0 clean, 1 findings, 2 usage error. CI runs it as an advisory (non-required) job.
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

const root=path.resolve(__dirname,'../..');
const defaultTarget='packages/Mothership/DAM/src/Resources/views';

// Blade directives. `value` ones render an expression and become `null`; the rest are removed.
const valueDirectives=['json','lang','choice','js','mothershipVite','vite','csrf','method'];
const blockDirectives=['if','elseif','else','endif','foreach','endforeach','for','endfor','forelse','endforelse','empty','endempty','while','endwhile','unless','endunless','isset','endisset','can','endcan','cannot','endcannot','canany','endcanany','auth','endauth','guest','endguest','push','endpush','pushOnce','endPushOnce','prepend','endprepend','pushIf','include','includeIf','includeWhen','includeUnless','includeFirst','each','stack','section','endsection','yield','production','endproduction','env','endenv','error','enderror','class','style','checked','selected','disabled','readonly','required','once','endonce','break','continue','switch','case','default','endswitch','dd','dump','props','aware','inject','use','session','endsession','slot','endslot','component','endcomponent','extends','hasSection','sectionMissing','show','stop','append','overwrite','parent','fragment','endfragment','php'];
const directiveNames=[...valueDirectives,...blockDirectives].sort((a,b)=>b.length-a.length);
const directiveRe=new RegExp(`(^|[^\\w@])@(${directiveNames.join('|')})\\b(?!\\s*=|\\.)`,'g');
// `@error="..."` / `@error.once="..."`: a Blade directive name used as a Vue event attribute.
const attrCollisionRe=new RegExp(`(\\s)@(${directiveNames.join('|')})((?:\\.[\\w-]+)*)\\s*=`,'g');

const newlines=text=>text.replace(/[^\n]/g,'');
const lineOf=(text,index)=>text.slice(0,index).split('\n').length;

// Blade comments never reach the browser, so they cannot hold a collision.
function stripComments(text){
    return text.replace(/\{\{--[\s\S]*?--\}\}/g,newlines);
}

// Index just past the balanced `(...)` that starts at `open`, or -1. Parentheses inside quoted strings are skipped.
function balanced(text,open){
    let depth=0;
    for(let i=open;i<text.length;i++){
        const c=text[i];
        if(c==='\''||c==='"'){
            for(i++;i<text.length&&text[i]!==c;i++)if(text[i]==='\\')i++;
        }else if(c==='(')depth++;
        else if(c===')'&&--depth===0)return i+1;
    }
    return -1;
}

// Turns Blade source into text a JS parser can read, keeping every newline so line numbers hold.
function neutralize(source){
    let text=stripComments(source);
    // Block `@php ... @endphp` only; inline `@php(...)` is consumed by the directive pass below.
    text=text.replace(/@php\b(?![ \t]*\()[\s\S]*?@endphp/g,newlines);
    text=text.replace(/@verbatim|@endverbatim/g,'');
    text=text.replace(/@\{\{([\s\S]*?)\}\}/g,(_, inner)=>`{{${inner}}}`.replace(/\{\{/g,'\u0001').replace(/\}\}/g,'\u0002'));
    text=text.replace(/\{!![\s\S]*?!!\}|\{\{[\s\S]*?\}\}/g,match=>`null${newlines(match)}`);
    text=text.replace(/@@/g,'\u0003');
    let out='';
    let last=0;
    directiveRe.lastIndex=0;
    for(let m;(m=directiveRe.exec(text));){
        const start=m.index+m[1].length;
        let end=start+1+m[2].length;
        const paren=/^[ \t]*\(/.exec(text.slice(end));
        if(paren){
            const close=balanced(text,end+paren[0].length-1);
            if(close>0)end=close;
        }
        out+=text.slice(last,start)+(valueDirectives.includes(m[2])?`null${newlines(text.slice(start,end))}`:newlines(text.slice(start,end)));
        last=end;
        directiveRe.lastIndex=end;
    }
    return (out+text.slice(last)).replace(/\u0001/g,'{{').replace(/\u0002/g,'}}').replace(/\u0003/g,'@');
}

function parse(code,kind){
    let wrapped;
    if(kind==='classic')wrapped=code;
    else if(kind==='module'){
        // Module scripts may use top-level await, so the body is parsed inside an async function once
        // import/export syntax, which only a module may use, is reduced to plain statements.
        const plain=code.replace(/^[ \t]*import\s[^;]*?;?[ \t]*$/gm,m=>m.replace(/[^\n]/g,'')).replace(/^([ \t]*)export\s+default\s+/gm,'$1').replace(/^([ \t]*)export\s+/gm,'$1');
        wrapped=`(async function(){${plain}\n})`;
    }else wrapped=kind==='expression'?`(async function(){ return (${code}\n); })`:`(async function(){${code}\n})`;
    try{
        new vm.Script(wrapped);
        return null;
    }catch(error){
        return error.message;
    }
}

const jsTypes=new Set(['','module','text/javascript','application/javascript']);

function lintSource(file,source){
    const findings=[];
    const add=(line,rule,message)=>findings.push({file,line,rule,message});
    // @verbatim output reaches the browser untouched, so Blade cannot swallow an attribute inside it.
    const visible=stripComments(source).replace(/@verbatim[\s\S]*?@endverbatim/g,newlines);

    for(const m of visible.matchAll(attrCollisionRe)){
        const line=lineOf(visible,m.index+m[1].length);
        add(line,'blade-directive-attr',`\`@${m[2]}${m[3]}=\` is read by Blade as its @${m[2]} directive, so Vue never sees the attribute; write \`v-on:${m[2]}${m[3]}=\` instead`);
    }

    // Echoes such as {{ route(..., ['a' => 'b']) }} hold `>`, so tags are only read after neutralising.
    const text=neutralize(source);
    const scriptRe=/<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
    for(const m of text.matchAll(scriptRe)){
        const type=(/\btype\s*=\s*["']([^"']*)["']/i.exec(m[1])||[,''])[1].trim().toLowerCase();
        if(!jsTypes.has(type))continue;
        const error=parse(m[2],type==='module'?'module':'classic');
        if(error)add(lineOf(text,m.index+m[0].indexOf('>')+1),'script-syntax',error);
    }

    // Vue attribute expressions (inline templates and x-template scripts alike). Blade components
    // (<x-...>) take PHP in `:prop="..."`, so only plain tags are read.
    const attrRe=/\s(@[\w.-]+|v-on:[\w.:-]+|:[\w.-]+|v-bind:[\w.-]+|v-if|v-else-if|v-show|v-model(?:\.\w+)*)=(?:"([^"]*)"|'([^']*)')/g;
    for(const tag of tags(text)){
        if(/^x-/i.test(tag.name))continue;
        for(const m of tag.attributes.matchAll(attrRe)){
            const expression=(m[2]??m[3]).replace(/&quot;/g,'"').replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&#0?39;/g,'\'');
            if(expression.trim()===''||/<\?|\u0001/.test(expression))continue;
            const isEvent=m[1].startsWith('@')||m[1].startsWith('v-on:');
            // An event handler is either an expression or statements, so it passes if either parses.
            const error=isEvent?(parse(expression,'expression')&&parse(expression,'body')):parse(expression,'expression');
            if(error)add(lineOf(text,tag.index+tag.attributes.indexOf(m[0])+1),'attr-syntax',`${m[1]}="${expression.length>60?expression.slice(0,57)+'...':expression}": ${error}`);
        }
    }
    return findings;
}

// Start tags with their raw attribute text; quotes are honoured so `>` inside a value does not end the tag.
function *tags(text){
    const startRe=/<([A-Za-z][\w:.-]*)/g;
    for(let m;(m=startRe.exec(text));){
        let i=startRe.lastIndex;
        for(;i<text.length&&text[i]!=='>';i++){
            if(text[i]==='"'||text[i]==='\''){
                const quote=text[i];
                for(i++;i<text.length&&text[i]!==quote;i++);
            }
        }
        yield {name:m[1],index:m.index,attributes:text.slice(startRe.lastIndex,i)};
        startRe.lastIndex=i;
    }
}

function collect(target){
    const stat=fs.statSync(target);
    if(stat.isFile())return [target];
    return fs.readdirSync(target,{withFileTypes:true}).flatMap(entry=>{
        const full=path.join(target,entry.name);
        if(entry.isDirectory())return entry.name==='node_modules'?[]:collect(full);
        return entry.name.endsWith('.blade.php')?[full]:[];
    });
}

function main(argv){
    const json=argv.includes('--json');
    const targets=argv.filter(arg=>!arg.startsWith('--'));
    const files=(targets.length?targets:[path.join(root,defaultTarget)]).flatMap(target=>{
        if(!fs.existsSync(target)){
            console.error(`lint-blade-scripts: no such path: ${target}`);
            process.exit(2);
        }
        return collect(target);
    }).sort();
    const findings=files.flatMap(file=>lintSource(path.relative(process.cwd(),file)||file,fs.readFileSync(file,'utf8')));
    if(json)console.log(JSON.stringify({files:files.length,findings},null,2));
    else{
        for(const f of findings)console.log(`${f.file}:${f.line}: [${f.rule}] ${f.message}`);
        console.log(`lint-blade-scripts: ${files.length} files, ${findings.length} finding${findings.length===1?'':'s'}`);
    }
    return findings.length?1:0;
}

if(require.main===module)process.exit(main(process.argv.slice(2)));
module.exports={lintSource,neutralize};
