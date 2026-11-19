(() => {
    'use strict';
    // Tracks started roots by element, not by a DOM attribute: when an SPA visit's mount lands after
    // start, Vue rebuilds #app from markup that already carried the marker, and the rebuilt root
    // would never start (Home stuck on "Loading your Home…"). Shared on window because every SPA
    // visit re-runs this file, and two copies must not both start the same root.
    const started = (window.__mothershipHomeStarted ??= new WeakSet());
    const start = async () => {
        const root = document.querySelector('[data-mothership-home]');
        if (!root || started.has(root)) return;
        started.add(root);
        const base = root.dataset.base;
        const api = window.MothershipHomeState;
        const $ = selector => root.querySelector(selector);
        const grid = $('[data-grid]');
        // A root the SPA mount rebuilt carries copies of whatever the replaced one had rendered: cards
        // without handlers, appended dialogs and menus. Start from the server markup's empty state.
        root.querySelectorAll('[data-board-rows],[data-deck],[data-glance],[data-needs],[data-cargo],[data-docked-body],[data-signal],[data-away-body],[data-saved-links]').forEach(slot=>slot.replaceChildren());
        root.querySelector('[data-section="links"]').hidden=true;
        root.querySelector('[data-calendar-open]').hidden=true;
        root.querySelectorAll('[data-cal-dialog],[data-access-menu]').forEach(element=>element.remove());
        root.querySelectorAll('dialog[open]').forEach(dialog=>dialog.removeAttribute('open'));
        $('[data-status]').textContent = '';
        let calendar = null;
        const lifecycle = new AbortController();
        const {signal} = lifecycle;
        let catalog = [];
        const launchId = () => globalThis.crypto?.randomUUID?.() || `home-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        const track = ({name,props,duration=null}) => {
            try { window.damTrack?.(name,null,props,'home',duration); } catch { /* Analytics never blocks Home. */ }
        };
        const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        const status = (message = '', error = false) => { $('[data-status]').textContent=message; $('[data-status]').classList.toggle('is-error',error); };
        // Failures read through the shared wording (admin request-errors plugin): never a raw
        // "Server Error" or "Failed to fetch", and a lapsed session raises the sign-in notice.
        // Without that plugin (a standalone page) the server's own message is kept, as before.
        const failureMessage = (failure, fallback) => (globalThis.mothership?.requestErrorMessage ? globalThis.mothership.requestErrorMessage(failure, fallback) : failure?.response?.data?.message) || fallback;
        async function request(path, {method='GET', body, timeout=0} = {}) {
            let response;
            try { response=await fetch(base+path,{method,signal:timeout?AbortSignal.timeout(timeout):undefined,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.csrf},body:body===undefined?undefined:JSON.stringify(body)}); }
            catch(error) { if(error?.name==='AbortError')throw error; throw new Error(failureMessage(error,'Couldn’t reach the server. Check your connection and retry.')); }
            const data=await response.json().catch(()=>({}));
            if (!response.ok) {
                if([401,419].includes(response.status))globalThis.mothership?.sessionExpired?.();
                throw new Error(response.status===409?'Home changed on another device. Reload this page to use the latest layout.':failureMessage({response:{status:response.status,data}},`Request failed (${response.status}). Please retry.`));
            }
            return data;
        }
        async function load() {
            try { const data=await request('/layout',{timeout:20000});if(signal.aborted||!root.isConnected)return;catalog=data.catalog;paintCalendar();savedLinks(data.links||[]);status(); }
            catch(error){const slow=['TimeoutError','AbortError'].includes(error?.name);status(slow?'Home is taking too long to load.':error.message,true);const retry=document.createElement('button');retry.type='button';retry.className='agh-button';retry.dataset.homeRetry='';retry.textContent='Retry';retry.onclick=()=>{status('Loading your Home…');load();};$('[data-status]').append(' ',retry);}
        }
        // The layout's catalogue says whether this account has the calendar.
        function paintCalendar(){$('[data-calendar-open]').hidden=!catalog.some(item=>item.id==='calendar');}
        document.addEventListener('mothership:navigate:success',()=>{
            if(root.isConnected)return;
            lifecycle.abort();
            // The mount replaced this root with a fresh one (start ran ahead of it): start that one.
            afterMount();
        },{signal});
        root.addEventListener('click',event=>{
            const input=event.detail===0?'keyboard':'pointer';
            const link=event.target.closest('a');const card=event.target.closest('[data-id]');
            if(link&&card)track({name:'home.feature.activated',props:{widget_id:card.dataset.id,action:'navigate',input,launch_id:launchId()}});
            const button=event.target.closest('button');if(!button)return;
            const id=button.closest('[data-id]')?.dataset.id;
            if(id)track({name:'home.feature.activated',props:{widget_id:id,action:'interact',input,launch_id:launchId()}});
            if(button.hasAttribute('data-calendar-open'))showCalendar('all');
        });
        // Superuser-only: right-click a card to set which custom roles get it on Home. The listener is
        // bound only for full-access admins, so everyone else keeps the native browser menu.
        if(root.dataset.superuser==='1'){
            let accessMenu=null,accessPlace=null;
            const closeAccess=()=>{accessMenu?.remove();accessMenu=null;accessPlace=null;};
            const openAccess=async(id,x,y)=>{
                closeAccess();
                const menu=document.createElement('div');
                menu.className='agh-access';menu.dataset.accessMenu='';
                menu.innerHTML='<p class="agh-access-head">Loading roles…</p>';
                // Mounted inside the Home root rather than <body>: the --agh-* theme variables are
                // scoped to [data-mothership-home], so a body-level menu renders with no background.
                root.append(menu);accessMenu=menu;
                // Keep the menu on screen. Size caps belong to CSS, where max-width and max-height are
                // already viewport-relative, so this only clamps coordinates. Writing a measured size
                // back as an inline cap would freeze the menu at its "Loading roles…" height once the
                // real content arrived, leaving a scrolling strip.
                const place=()=>{const box=menu.getBoundingClientRect();menu.style.left=`${Math.max(8,Math.min(x,innerWidth-box.width-8))}px`;menu.style.top=`${Math.max(8,Math.min(y,innerHeight-box.height-8))}px`;};
                accessPlace=place;menu.style.left=`${x}px`;menu.style.top=`${y}px`;place();
                try{
                    const data=await request(`/widget-access/${encodeURIComponent(id)}`);
                    if(accessMenu!==menu)return;
                    const rows=data.roles.map(role=>`<label class="agh-access-row"><input type="checkbox" data-role="${role.id}"${role.enabled?' checked':''}><span>${esc(role.name)}</span></label>`).join('')
                        ||'<p class="agh-access-empty">No custom roles yet. Create one in Settings → Roles.</p>';
                    menu.innerHTML=`<p class="agh-access-head">${esc(data.widget.title)}</p><p class="agh-access-note">Roles that get this on Home${data.widget.permission?` · ticking also grants ${esc(data.widget.permission)}, and unticking leaves it granted`:''}</p>${rows}`;
                    place();
                    menu.querySelectorAll('[data-role]').forEach(box=>box.addEventListener('change',async()=>{
                        box.disabled=true;
                        try{await request(`/widget-access/${encodeURIComponent(id)}`,{method:'PUT',body:{role_id:Number(box.dataset.role),enabled:box.checked}});status(`${data.widget.title} ${box.checked?'enabled':'disabled'} for that role.`);}
                        catch(error){box.checked=!box.checked;status(error.message,true);}
                        finally{box.disabled=false;}
                    },{signal}));
                }catch(error){if(accessMenu===menu){menu.innerHTML=`<p class="agh-access-empty">${esc(error.message)}</p>`;place();}}
            };
            grid.addEventListener('contextmenu',event=>{const card=event.target.closest('[data-id]');if(!card||catalog.find(widget=>widget.id===card.dataset.id)?.always_available)return;event.preventDefault();openAccess(card.dataset.id,event.clientX,event.clientY);},{signal});
            document.addEventListener('click',event=>{if(accessMenu&&!event.target.closest('[data-access-menu]'))closeAccess();},{signal});
            document.addEventListener('keydown',event=>{if(event.key==='Escape')closeAccess();},{signal});
            // The menu is mounted outside #app, so an SPA teardown must remove it explicitly or its
            // in-flight request can repopulate a menu belonging to a page that is already gone.
            signal.addEventListener('abort',closeAccess,{once:true});
            // An open menu holds coordinates measured against the old viewport; re-clamp on resize.
            window.addEventListener('resize',()=>accessPlace?.(),{signal});
        }
        function calendarApi(){const factory=window.MothershipHomeCalendar;if(!factory)return null;calendar??=factory.create({root,request,esc});return calendar;}
        // Quick links a person saved while Home was customizable stay reachable, read-only.
        function savedLinks(links){
            const section=root.querySelector('[data-section="links"]');
            section.hidden=!links.length;
            $('[data-saved-links]').innerHTML=links.map(link=>`<a href="${esc(link.url)}"${new URL(link.url,location.origin).origin!==location.origin?' target="_blank" rel="noopener noreferrer"':''}>${esc(link.title)} <span aria-hidden="true">↗</span></a>`).join('');
        }
        const calendarDialog=$('[data-calendar-dialog]');
        let calendarMounted=false;
        function showCalendar(filter){const api=calendarApi();if(!api)return;if(!calendarMounted){api.mount(root.querySelector('[data-slot="coming"] [data-id="calendar"]'));calendarMounted=true;}if(filter)api.show(filter);if(!calendarDialog.open)calendarDialog.showModal();}
        calendarDialog.addEventListener('click',event=>{if(event.target.closest('[data-calendar-close]'))calendarDialog.close();},{signal});

        // The iPhone app is the installed web app: Add your iPhone, at the foot of Docked, opens the three steps.
        const phoneDialog=$('[data-phone-dialog]');let phoneOpener=null;
        root.addEventListener('click',event=>{
            const opener=event.target.closest('[data-phone-open]');
            if(opener){phoneOpener=opener;if(!phoneDialog.open)phoneDialog.showModal();}
            if(event.target.closest('[data-phone-close]'))phoneDialog.close();
        },{signal});
        phoneDialog.addEventListener('close',()=>{if(phoneOpener?.isConnected)phoneOpener.focus();},{signal});


        // Upload from Home: pick the folder, then the files. The folder is remembered in this browser (the calendar's
        // "Add work" uses the same key), so the second upload is one step.
        const folderKey='mothership.calendar.folder';
        const savedFolder=()=>{try{return JSON.parse(localStorage.getItem(folderKey)||'null');}catch{return null;}};
        const upload={folders:null,term:'',busy:'',error:'',done:null,choosing:false,opener:null};
        const uploadDialog=$('[data-upload-dialog]');
        async function uploadFolders(term=''){
            const searching=term.trim().length>=2;
            try{
                const response=await fetch(searching?`${root.dataset.directorySearch}?q=${encodeURIComponent(term.trim())}`:root.dataset.directories,{credentials:'same-origin',headers:{Accept:'application/json'}});
                const data=await response.json();
                if(!response.ok||upload.term!==term||!uploadDialog.open)return;
                // A lone top folder ("Root") only holds the real ones: list what is inside it, by plain name.
                const tops=data.data||[];
                upload.folders=searching?tops:tops.length===1&&(tops[0].children||[]).length?tops[0].children.map(child=>({id:child.id,name:child.name})):tops.flatMap(top=>[{id:top.id,name:top.name},...(top.children||[]).map(child=>({id:child.id,name:child.name,path_names:[top.name,child.name]}))]).slice(0,30);
                paintUpload({keepFocus:searching});
            }catch{ if(upload.term===term&&uploadDialog.open){upload.folders=[];paintUpload({keepFocus:searching});} }
        }
        function paintUpload({keepFocus=false}={}){
            const folder=savedFolder(),picking=!folder||upload.choosing;
            const folderName=item=>(item.path_names||[item.name]).join(' / ');
            const head='<div class="agh-dialog-head"><h2 id="agh-upload-title">Upload files</h2><button type="button" class="agh-button" data-upload-close aria-label="Close upload">✕</button></div>';
            if(upload.done){
                uploadDialog.innerHTML=`${head}<div class="agh-empty-note" role="status"><strong>${upload.done.count} ${upload.done.count===1?'file is':'files are'} in “${esc(upload.done.folder)}”.</strong><p>They also appear on the arrivals board.</p><div class="agh-actions"><button type="button" class="agh-button" data-upload-again>Upload more</button><a class="agh-button agh-primary" href="${esc(root.dataset.library)}">See them in the Library</a></div></div>`;
            }else{
                uploadDialog.innerHTML=`${head}<ol class="agh-steps">
                    <li><strong>1. Choose the folder they go in</strong>${picking
                        ?`<label>Find a folder<input type="search" data-upload-folder value="${esc(upload.term)}" placeholder="Type part of a folder name" autocomplete="off" /></label>${upload.folders===null?'<p class="agh-caption">Loading folders…</p>':upload.folders.length?`<div class="agh-upload-folders">${upload.folders.map(item=>`<button type="button" class="agh-button" data-upload-pick="${Number(item.id)}" data-name="${esc(folderName(item))}">${esc(folderName(item))}</button>`).join('')}</div>`:`<p class="agh-caption">${upload.term.trim().length>=2?'No folder has that name. Try fewer letters.':'There are no folders you can upload to yet. Ask an administrator to share one with you.'}</p>`}`
                        :`<p class="agh-upload-folder"><span>Folder: <b>${esc(folder.name)}</b></span> <button type="button" class="agh-button" data-upload-change>Choose a different folder</button></p>`}</li>
                    <li><strong>2. Choose the files</strong>${picking?'<p class="agh-caption">Pick a folder first.</p>':`<label class="agh-button agh-primary agh-upload-choose"${upload.busy?' aria-disabled="true"':''}>${upload.busy?esc(upload.busy):'Choose files from this computer'}<input type="file" multiple hidden data-upload-files ${upload.busy?'disabled':''} /></label>`}</li>
                </ol>${upload.error?`<p class="agh-signal-state is-offline" role="alert">${esc(upload.error)}</p>`:''}`;
            }
            if(keepFocus){const again=uploadDialog.querySelector('[data-upload-folder]');again?.focus();again?.setSelectionRange(again.value.length,again.value.length);}
            else if(uploadDialog.open&&!uploadDialog.contains(document.activeElement))uploadDialog.querySelector('[data-upload-folder],[data-upload-close]')?.focus();
        }
        function openUpload(opener){
            Object.assign(upload,{folders:null,term:'',busy:'',error:'',done:null,choosing:false,opener});
            paintUpload();if(!uploadDialog.open)uploadDialog.showModal();
            if(!savedFolder())uploadFolders('');
        }
        async function sendUpload(files){
            const folder=savedFolder();if(!files.length||!folder||upload.busy)return;
            const form=new FormData();form.append('directory_id',folder.id);[...files].forEach(file=>form.append('files[]',file));
            upload.busy=`Uploading ${files.length} ${files.length===1?'file':'files'}…`;upload.error='';paintUpload();
            try{
                const response=await fetch(root.dataset.upload,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':root.dataset.csrf},body:form});
                const data=await response.json().catch(()=>({}));
                if([401,419].includes(response.status))globalThis.mothership?.sessionExpired?.();
                if(!response.ok||!data.success)throw new Error(data.message||`The upload did not finish (${response.status}). Nothing was added. Try again.`);
                upload.busy='';upload.done={count:(data.files||[]).length||files.length,folder:folder.name};paintUpload();
                // The new files belong on the board.
                bridge.refresh();
            }catch(error){upload.busy='';upload.error=error.message;paintUpload();}
        }
        let folderTimer=0;
        uploadDialog.addEventListener('input',event=>{if(!event.target.matches('[data-upload-folder]'))return;upload.term=event.target.value;clearTimeout(folderTimer);const term=upload.term;folderTimer=setTimeout(()=>uploadFolders(term),250);},{signal});
        uploadDialog.addEventListener('change',event=>{if(event.target.matches('[data-upload-files]'))sendUpload(event.target.files);},{signal});
        uploadDialog.addEventListener('click',event=>{
            const button=event.target.closest('button');if(!button)return;
            if(button.hasAttribute('data-upload-close'))uploadDialog.close();
            if(button.dataset.uploadPick){try{localStorage.setItem(folderKey,JSON.stringify({id:Number(button.dataset.uploadPick),name:button.dataset.name}));upload.choosing=false;upload.error='';}catch{upload.error='This browser would not remember the folder. Turn off private browsing and try again.';}upload.term='';paintUpload();uploadDialog.querySelector('.agh-upload-choose')?.focus();}
            if(button.hasAttribute('data-upload-change')){upload.choosing=true;upload.folders=null;upload.term='';paintUpload();uploadFolders('');}
            if(button.hasAttribute('data-upload-again')){upload.done=null;paintUpload();}
        },{signal});
        uploadDialog.addEventListener('close',()=>{if(upload.opener?.isConnected)upload.opener.focus();},{signal});
        // The top bar's Upload button is outside this root: on Home it opens the dialog instead of leaving for the Library.
        // On window, in the capture phase: the shell's link navigation listens on document and skips a click already handled.
        window.addEventListener('click',event=>{const button=event.target.closest?.('[data-bridge-upload]');if(!button||!uploadDialog)return;event.preventDefault();openUpload(button);},{signal,capture:true});
        const bridge=window.MothershipHomeBridge.create({root,request,signal,state:api});
        bridge.start();
        await load();
    };
    // Vue mounts synchronously on DOMContentLoaded (and after SPA script injection). Start on the next task.
    const afterMount=()=>setTimeout(start,0);
    // The Admin app mounts on DOMContentLoaded; waiting for `load` instead left Home empty while any font or image stalled.
    // After SPA injection readyState is already `interactive` (DOMContentLoaded fired long ago) while a stalled subresource holds `load`.
    if(document.readyState!=='loading')afterMount();else{document.addEventListener('DOMContentLoaded',afterMount,{once:true});window.addEventListener('load',afterMount,{once:true});}
})();
