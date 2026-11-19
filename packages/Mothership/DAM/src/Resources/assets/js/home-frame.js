(() => {
    'use strict';
    const config=window.__MOTHERSHIP_FRAME__;
    if(!config)return;
    const state={...config.state};
    let timer;
    const notify=(type,payload={})=>{if(window.parent!==window)window.parent.postMessage({protocol:'mothership-widget/v1',type,payload},location.origin);};
    // A save that fails is reported as work, so Home asks before it closes the tool.
    let pending=false;
    const send=()=>{
        pending=false;
        const body=JSON.stringify({state});
        // keepalive lets a save, including one already in flight, outlive a closed or reloaded frame.
        // Browsers allow 64 KB of keepalive bodies, counted in bytes. A larger save could be cut off
        // by closing the tool, so it is reported as work and Home asks first.
        const keepalive=new TextEncoder().encode(body).length<=60000;
        if(!keepalive)notify('dirty');
        return fetch(config.url,{method:'PUT',credentials:'same-origin',keepalive,headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':config.csrf},body}).then(response=>{if(!response.ok)throw Error();}).catch(()=>{notify('preference-error',{message:'Widget preferences could not be saved. Keep this workspace open and retry later.'});notify('dirty');});
    };
    const persist=()=>{
        clearTimeout(timer);
        pending=true;
        timer=setTimeout(()=>send(),450);
    };
    // Closing or reloading a tool inside the debounce would drop its last change, so send it on the way out.
    window.addEventListener('pagehide',()=>{if(pending){clearTimeout(timer);send();}});
    // A frame sees only its own account-backed storage, including legacy widget keys.
    const scoped={getItem(key){return Object.hasOwn(state,key)?state[key]:null;},setItem(key,value){state[key]=String(value);persist();},removeItem(key){delete state[key];persist();},clear(){Object.keys(state).forEach(key=>delete state[key]);persist();},key(index){return Object.keys(state)[index]??null;},get length(){return Object.keys(state).length;}};
    Object.defineProperty(window,'localStorage',{value:scoped,configurable:true});
    // Search and filter fields inside a [data-agh-ephemeral] region never hold unexported work, so
    // typing in them must not arm Home's leave-page prompt (a tool search, then Open, would
    // otherwise ask the tester to discard "work" before the tool could load).
    document.addEventListener('input',event=>{if(event.target?.closest?.('[data-agh-ephemeral]'))return;notify('dirty');},{capture:true});
    document.addEventListener('drop',()=>notify('dirty'),{capture:true});
    // An Escape that closes a sheet inside the tool is the tool's own: it is not passed on as a wish to leave.
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!document.querySelector('dialog[open]')){notify('escape');}});
    document.addEventListener('DOMContentLoaded',()=>{
        // reportsWork: the listeners above tell Home when work starts, so Home may close this tool
        // without asking while none has been reported.
        notify('ready',{widget:config.widget,reportsWork:true});
    });
    const setTheme=theme=>{
        if(['light','dark'].includes(theme)){document.documentElement.dataset.theme=theme;document.documentElement.style.colorScheme=theme;}
    };
    setTheme(new URLSearchParams(location.search).get('theme'));
    // A tool cannot see the fold of a folded screen from inside its frame, so the page that frames it says where the
    // fold crosses the tool: <html data-fold="columns|rows"> with --fold-start and --fold-end, the strip's near and
    // far edge in this page's own pixels. The kit's panes and sheets keep clear of that strip.
    const setFold=fold=>{
        const root=document.documentElement;
        const mode=['columns','rows'].includes(fold?.mode)&&Number.isFinite(fold.start)&&Number.isFinite(fold.end)&&fold.start>0&&fold.end>=fold.start?fold.mode:null;
        if(mode){root.dataset.fold=mode;root.style.setProperty('--fold-start',`${Math.round(fold.start)}px`);root.style.setProperty('--fold-end',`${Math.round(fold.end)}px`);}
        else{delete root.dataset.fold;root.style.removeProperty('--fold-start');root.style.removeProperty('--fold-end');}
        window.dispatchEvent(new CustomEvent('mothership:fold',{detail:{mode}}));
    };
    window.addEventListener('message',event=>{
        if(event.source!==window.parent||event.origin!==location.origin||event.data?.protocol!=='mothership-widget/v1')return;
        if(event.data.type==='theme')setTheme(event.data.payload?.theme);
        if(event.data.type==='fold')setFold(event.data.payload);
        if(event.data.type==='command')commands.get(event.data.payload?.name)?.(event.data.payload);
    });
    const originalFetch=window.fetch.bind(window);
    window.fetch=(input,options={})=>{
        const url=new URL(input instanceof Request?input.url:String(input),location.href);
        const method=String(options.method||input?.method||'GET').toUpperCase();
        if(url.origin===location.origin&&!['GET','HEAD','OPTIONS'].includes(method)){const headers=new Headers(options.headers||(input instanceof Request?input.headers:{}));headers.set('X-CSRF-TOKEN',config.csrf);options={...options,headers};}
        return originalFetch(input,options);
    };
    // The tool frame's bar (dam::tools.frame) shows what a tool says it has, and tells the tool what was pressed.
    // chrome({set, sets:[{id,name,current}], setsLabel, manage, export, library}) is sent whenever any of it changes.
    // on(name, handler) takes the bar's commands: 'open-set' ({id}), 'manage-sets', 'export' and 'library'.
    // notice({text, tone, link:{label,url}}) puts a line in the bar's status, such as where a file was saved.
    const commands=new Map();
    const library=config.library||null;
    window.MothershipFrame={
        // Whether the page that frames this tool draws a bar for it. Where none is drawn (Home's panel), a tool
        // shows its own way to its saved sets, Export and Save to Library.
        bar:window.parent!==window&&new URLSearchParams(location.search).get('bar')==='1',
        chrome(shown){notify('chrome',{...shown,library:Boolean(shown?.library&&library)});},
        on(name,handler){commands.set(name,handler);},
        notice(shown){notify('notice',shown);},
        // Whether this account may save into the Library at all.
        library:Boolean(library),
        // The folders this account may save into, outermost first: [{id, name, depth}].
        async folders(){
            if(!library)return [];
            const response=await window.fetch(library.folders,{headers:{Accept:'application/json'},credentials:'same-origin'});
            if(!response.ok)throw Error(`The Library answered ${response.status}.`);
            const rows=[];
            const walk=(nodes,depth)=>(nodes||[]).forEach(node=>{rows.push({id:String(node.id),name:String(node.name),depth});walk(node.children,depth+1);});
            walk((await response.json()).data,0);
            return rows;
        },
        // Saves files ([{name, blob}]) into one folder of the Library, recorded as made with this tool. Answers
        // {count, url}, url being the first file's page, or throws with words a person can read.
        async save({files,folder}){
            if(!library)throw Error('This account cannot save to the Library.');
            const form=new FormData();
            files.forEach(file=>form.append('files[]',new File([file.blob],file.name,{type:file.blob.type||'application/octet-stream'})));
            form.append('directory_id',folder);
            const response=await window.fetch(library.save,{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
            const body=await response.json().catch(()=>({}));
            if(!response.ok||body.success===false)throw Error(body.message||(response.status===403?'You cannot save to that folder.':`The Library answered ${response.status}.`));
            const saved=Array.isArray(body.files)?body.files:[];
            return {count:saved.length||files.length,url:saved[0]?.id?library.open.replace('__ID__',encodeURIComponent(saved[0].id)):null};
        },
    };
    // Legacy Axios widget requests receive the same CSRF protection as the host.
    const originalOpen=XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open=function(method,url,...args){this.__homeWrite=!['GET','HEAD','OPTIONS'].includes(String(method).toUpperCase())&&new URL(url,location.href).origin===location.origin;return originalOpen.call(this,method,url,...args);};
    const originalSend=XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send=function(body){if(this.__homeWrite)this.setRequestHeader('X-CSRF-TOKEN',config.csrf);return originalSend.call(this,body);};
})();
