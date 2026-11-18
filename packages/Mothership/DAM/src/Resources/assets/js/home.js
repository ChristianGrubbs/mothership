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
        root.querySelectorAll('[data-board-rows],[data-deck],[data-glance],[data-needs],[data-cargo],[data-docked],[data-signal],[data-away],[data-saved-links]').forEach(slot=>slot.replaceChildren());
        root.querySelectorAll('[data-tools] li[data-tool]').forEach(item=>item.remove());
        root.querySelector('[data-section="links"]').hidden=true;
        root.querySelectorAll('[data-cal-dialog],[data-access-menu]').forEach(element=>element.remove());
        root.querySelectorAll('dialog[open]').forEach(dialog=>dialog.removeAttribute('open'));
        $('[data-status]').textContent = '';
        const sessions = api.sessions();
        const frames = new Map();
        let calendar = null;
        const lifecycle = new AbortController();
        const {signal} = lifecycle;
        let catalog = [], widgets = [];
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
            try { const data=await request('/layout',{timeout:20000});if(signal.aborted||!root.isConnected)return;catalog=data.catalog;widgets=data.widgets;render();savedLinks(data.links||[]);status(); }
            catch(error){const slow=['TimeoutError','AbortError'].includes(error?.name);status(slow?'Home is taking too long to load.':error.message,true);const retry=document.createElement('button');retry.type='button';retry.className='agh-button';retry.dataset.homeRetry='';retry.textContent='Retry';retry.onclick=()=>{status('Loading your Home…');load();};$('[data-status]').append(' ',retry);}
        }
        // The Tools shelf is the only part of Home drawn from the layout: the Bridge reads the summary.
        function render(){paintTools();}
        function theme(){return document.documentElement.classList.contains('dark')?'dark':'light';}
        function sendTheme(entry){entry.iframe.contentWindow?.postMessage({protocol:'mothership-widget/v1',type:'theme',payload:{theme:theme()}},location.origin);}
        // A tool's "I hold nothing to export" declaration belongs to the document that sent it: a reload
        // or an in-frame navigation loads a new document, which must declare again or be treated as
        // holding work.
        function holdsNoWork(entry){try{return Boolean(entry.noWorkDocument)&&entry.noWorkDocument===entry.iframe.contentDocument;}catch{return false;}}
        // The bridge (home-frame.js) says in its ready message that it reports work as it starts
        // (payload.reportsWork:true). That promise, too, belongs to the document that made it.
        function reportsWork(entry){try{return Boolean(entry.reportsWorkDocument)&&entry.reportsWorkDocument===entry.iframe.contentDocument;}catch{return false;}}
        // Closing asks only when work may be lost: the tool reported work, or nothing in its current
        // document promised to report it (a tool without the bridge, a frame that left the tool, a
        // tool that has not loaded). A tool that holds no work never asks.
        function mayHoldWork(entry){return !holdsNoWork(entry)&&(sessions.isDirty(entry.id)||!reportsWork(entry));}
        function sendActive(entry,active){entry.iframe.contentWindow?.postMessage({protocol:'mothership-widget/v1',type:'active',payload:{active}},location.origin);}
        // Gadget popups offer Reload and Close tool only (operator directive 2026-09-13); there is no
        // fold-away control. hide() is the internal step of closing; nothing keeps a hidden workspace.
        function hide() {
            const entry=frames.get(sessions.active);if(!entry)return;
            sendActive(entry,false);
            entry.element.hidden=true;sessions.collapse();root.closest('#app')?.removeAttribute('inert');
            render();grid.querySelector(`[data-open="${entry.id}"]`)?.focus();
        }
        function open(id, opener, input='pointer') {
            const definition=catalog.find(item=>item.id===id);if(!definition?.url)return;
            const attemptId=launchId();
            track({name:'home.feature.activated',props:{widget_id:id,action:frames.has(id)?'resume':'open',input,launch_id:attemptId}});
            if(sessions.active)hide();
            sessions.open(id);
            let entry=frames.get(id);
            if(!entry) {
                const began=performance.now();let outcome=false;
                const finishLaunch=({phase,error_code})=>{
                    if(outcome)return;outcome=true;
                    // Frame identity proves delivery, not that an app's interaction or export works.
                    const name=phase==='loaded'?'home.launch.loaded':phase==='abandoned'?'home.launch.abandoned':'home.launch.failed';
                    track({name,props:{widget_id:id,launch_id:attemptId,phase,...(error_code?{error_code}:{})},duration:Math.max(0,Math.round(performance.now()-began))});
                };
                track({name:'home.launch.attempted',props:{widget_id:id,launch_id:attemptId,phase:'opening'}});
                const element=document.createElement('section');element.className='agh-workspace';element.setAttribute('role','dialog');element.setAttribute('aria-modal','true');element.setAttribute('aria-label',definition.title);
                element.innerHTML=`<div class="agh-workspace-head"><div><h2>${esc(definition.title)}</h2><p>Export your work before closing the tool.</p></div><div class="agh-actions" data-tool-actions><button class="agh-button" data-tool-reload>Reload</button><button class="agh-button agh-primary" data-tool-close>Close tool</button></div><div class="agh-actions" data-tool-confirm hidden style="display:none"><button class="agh-button" data-tool-confirm-cancel>Cancel</button><button class="agh-button agh-primary" data-tool-confirm-accept>Discard</button></div></div><div class="agh-frame-status" role="status">Opening ${esc(definition.title)}…</div><div class="agh-frame-status" data-tool-prompt role="alert" hidden></div>`;
                const iframe=document.createElement('iframe');iframe.title=definition.title;iframe.allow='fullscreen; clipboard-read; clipboard-write';
                const url=new URL(definition.url);url.searchParams.set('mode','embedded');url.searchParams.set('embed','1');url.searchParams.set('theme',theme());url.searchParams.set('account',root.dataset.user);
                if(id==='looper')url.searchParams.set('apiBase',base+'/service/looper');
                iframe.src=url.href;
                entry={element,iframe,opener,id};frames.set(id,entry);element.append(iframe);document.body.append(element);
                const message=element.querySelector('.agh-frame-status:not([data-tool-prompt])');
                let timeout=setTimeout(()=>{track({name:'home.launch.slow',props:{widget_id:id,launch_id:attemptId,phase:'timeout'},duration:Math.max(0,Math.round(performance.now()-began))});message.hidden=false;message.textContent='This tool is taking longer to open. You can reload it or close the tool and keep using Home.';},30000);
                // A tool page identifies itself through __MOTHERSHIP_FRAME__. The first load that does not is a
                // broken session. A later load that does not is the tool navigating away from itself (e.g. to an
                // asset page): that page belongs to the full window, never nested inside the popup with a second
                // copy of the admin chrome and a false "could not open" banner (operator report 2026-09-15).
                // Only a page gets the window. A file (an image, a video, a PDF) would replace Home with a preview
                // that has no way back in the app installed to an iPhone Home Screen, so the window stays here and
                // the popup's Reload and Close tool are the way on (operator report 2026-11-11).
                entry.abandonPendingLaunch=()=>{clearTimeout(timeout);if(!outcome)finishLaunch({phase:'abandoned'});};
                iframe.addEventListener('load',()=>{clearTimeout(timeout);let identity;try{identity=iframe.contentWindow?.__MOTHERSHIP_FRAME__?.widget;}catch{identity=null;}if(identity!==id){
                    let escaped='',page=false;try{escaped=entry.booted?iframe.contentWindow.location.href:'';page=['text/html','application/xhtml+xml'].includes(iframe.contentDocument?.contentType);}catch(error){escaped='';}
                    if(escaped&&escaped!=='about:blank'&&page){window.location.assign(escaped);return;}
                    if(escaped&&escaped!=='about:blank'){message.hidden=false;message.textContent='This tool opened a file here. Use Reload to get the tool back, or Close tool to return to Home.';return;}
                    finishLaunch({phase:'error',error_code:'frame_identity_missing'});message.hidden=false;message.textContent='This tool could not open. Reload Home to check your session, or try Reload.';return;}
                    entry.booted=true;finishLaunch({phase:'loaded'});message.hidden=true;sendTheme(entry);sendActive(entry,sessions.active===id);});
                iframe.addEventListener('error',()=>{clearTimeout(timeout);finishLaunch({phase:'error',error_code:'frame_load_failed'});message.hidden=false;message.textContent='This tool could not load. Try Reload.';});
                // The confirmation lives in the header, never in window.confirm: a browser that
                // suppresses dialogs (Chrome's "prevent additional dialogs") makes confirm() return
                // falsy without asking, and since Collapse is gone that would leave the popup with no
                // working exit and Home inert behind it (operator report 2026-09-13).
                const toolActions=element.querySelector('[data-tool-actions]'),confirmActions=element.querySelector('[data-tool-confirm]');
                const acceptButton=element.querySelector('[data-tool-confirm-accept]'),cancelButton=element.querySelector('[data-tool-confirm-cancel]');
                const prompt=element.querySelector('[data-tool-prompt]');
                const showGroup=(node,on)=>{node.hidden=!on;node.style.display=on?'':'none';};
                let pending=null;
                const clearConfirm=focusOpener=>{
                    const previous=pending;pending=null;showGroup(confirmActions,false);showGroup(toolActions,true);prompt.hidden=true;
                    if(previous&&focusOpener)previous.opener?.focus();
                };
                const dismissConfirm=()=>{if(!pending)return false;clearConfirm(true);return true;};
                // The question gets its own strip below the status line, never the status element
                // itself: a load, timeout, preference-error or export can write there while the
                // prompt is up, which would erase the question or be erased by restoring it.
                const askConfirm=({question,label,opener,action})=>{
                    if(pending)return;
                    pending={opener,action};
                    acceptButton.textContent=label;prompt.textContent=question;prompt.hidden=false;
                    showGroup(toolActions,false);showGroup(confirmActions,true);acceptButton.focus();
                };
                cancelButton.onclick=()=>dismissConfirm();
                // Focus returns to the control that raised the prompt before the action runs: an
                // accepted Reload keeps the popup open, so without this the focused accept button is
                // hidden underneath the caret and focus falls back to the document body. A close then
                // moves focus on to the launcher as it tears the workspace down.
                acceptButton.onclick=()=>{const action=pending?.action;clearConfirm(true);action?.();};
                entry.clearConfirm=()=>clearConfirm(false);
                // Tear down first, render Home once, then focus the freshly rendered launcher: a second
                // render would replace the grid children and drop the focus hide() had just placed.
                entry.close=()=>{
                    clearTimeout(timeout);if(!outcome)finishLaunch({phase:'abandoned'});
                    sendActive(entry,false);element.remove();frames.delete(id);sessions.discard(id);root.closest('#app')?.removeAttribute('inert');
                    render();grid.querySelector(`[data-open="${id}"]`)?.focus();
                };
                entry.askClose=()=>askConfirm({question:'Close this tool? Work you have not exported is discarded.',label:'Discard and close',opener:element.querySelector('[data-tool-close]'),action:entry.close});
                entry.dismissConfirm=dismissConfirm;
                // Close tool asks only when work may be lost (mayHoldWork): asking to discard work that
                // does not exist cost 9 of 11 recorded closes a second "Discard and close" click
                // (PostHog review 2026-11-11). Reload still asks, and Escape always asks, so a stray
                // key never closes a tool.
                element.querySelector('[data-tool-close]').onclick=()=>{if(!frames.has(id))return;if(mayHoldWork(entry))entry.askClose();else entry.close();};
                element.querySelector('[data-tool-reload]').onclick=()=>askConfirm({question:'Reload this tool? Work you have not exported is discarded.',label:'Discard and reload',opener:element.querySelector('[data-tool-reload]'),action:()=>{
                    // The old document stays usable until the new one arrives, and what it reported is
                    // forgotten here. Its promise is dropped and not taken again (its ready may still be
                    // on the way): until a new document makes its own promise, Close tool asks.
                    sessions.markDirty(id,false);entry.reportsWorkDocument=null;try{entry.discardedDocument=iframe.contentDocument;}catch{entry.discardedDocument=null;}message.hidden=false;message.textContent='Reloading…';iframe.src=url.href;}});
                // Escape backs out of the prompt first, so a stray Escape (a select or the DAM picker
                // inside the tool) can never discard work; a second Escape asks to close.
                element.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();if(!dismissConfirm())entry.askClose();}if(event.key==='Tab'){const nodes=[...element.querySelectorAll('button,iframe')].filter(node=>node.tagName==='IFRAME'||node.offsetParent!==null);if(!nodes.length)return;if(event.shiftKey&&document.activeElement===nodes[0]){event.preventDefault();nodes.at(-1).focus();}else if(!event.shiftKey&&document.activeElement===nodes.at(-1)){event.preventDefault();nodes[0].focus();}}});
            }
            entry.opener=opener||entry.opener;entry.clearConfirm();entry.element.hidden=false;root.closest('#app')?.setAttribute('inert','');entry.element.querySelector('[data-tool-close]').focus();
            sendActive(entry,true);
        }
        window.addEventListener('message',event=>{
            const entry=[...frames.values()].find(entry=>api.acceptMessage({event,frame:entry.iframe.contentWindow,origin:location.origin}));if(!entry)return;
            const data=event.data;
            if(data.type==='ready')sendTheme(entry);
            // A catalogue-style tool says so in its own ready message (payload.holdsWork:false). Nothing
            // typed into it (a search) is work, so it never arms the close or leave-page prompts. The
            // bridge's own ready carries no holdsWork and changes nothing. holdsWork:true withdraws it and
            // is itself a report of work: a tool that says it holds work is asked about, whether or not
            // it also sends dirty.
            if(data.type==='ready'&&typeof data.payload?.holdsWork==='boolean'){
                try{entry.noWorkDocument=data.payload.holdsWork?null:entry.iframe.contentDocument;}catch{entry.noWorkDocument=null;}
                if(holdsNoWork(entry))sessions.markDirty(entry.id,false);
                else if(data.payload.holdsWork)sessions.markDirty(entry.id,true);
            }
            if(data.type==='ready'&&data.payload?.reportsWork===true){try{const current=entry.iframe.contentDocument;if(current!==entry.discardedDocument){entry.reportsWorkDocument=current;entry.discardedDocument=null;}}catch{entry.reportsWorkDocument=null;}}
            if(data.type==='preference-error'){const message=entry.element.querySelector('.agh-frame-status:not([data-tool-prompt])');message.hidden=false;message.textContent=data.payload?.message||'Preferences could not be saved.';}
            // params is a tool's report of its own state, which a tool may send as it finishes loading,
            // before anyone has touched it. A document with the bridge reports work through dirty, so its
            // params are not counted. A frame without the bridge cannot say, so its params still count.
            const work=['dirty','media-loaded'].includes(data.type)||(data.type==='params'&&!reportsWork(entry));
            if(work&&!holdsNoWork(entry))sessions.markDirty(entry.id,true);
            if(data.type==='escape'&&sessions.active===entry.id&&!entry.dismissConfirm())entry.askClose();
        },{signal});
        const observer=new MutationObserver(()=>frames.forEach(sendTheme));observer.observe(document.documentElement,{attributes:true,attributeFilter:['class']});
        const hasWork=()=>sessions.hasDirty();
        window.addEventListener('beforeunload',event=>{if(hasWork()){event.preventDefault();event.returnValue='';}},{signal});
        window.__mothershipHomeCanLeave=()=>!hasWork()||confirm('Leave Home? Work you have not exported from an open tool will be lost.');
        if(!window.__mothershipHomeGuardRegistered&&window.mothership?.registerNavigationGuard){
            window.mothership.registerNavigationGuard(()=>window.__mothershipHomeCanLeave?.()??true);
            window.__mothershipHomeGuardRegistered=true;
        }
        document.addEventListener('mothership:navigate:success',()=>{
            if(root.isConnected)return;
            window.__mothershipHomeCanLeave=null;
            frames.forEach(entry=>{entry.abandonPendingLaunch?.();entry.element.remove();});frames.clear();observer.disconnect();lifecycle.abort();
            // The mount replaced this root with a fresh one (start ran ahead of it): start that one.
            afterMount();
        },{signal});
        root.addEventListener('click',event=>{
            const input=event.detail===0?'keyboard':'pointer';
            const link=event.target.closest('a');const card=event.target.closest('[data-id]');
            if(link&&card)track({name:'home.feature.activated',props:{widget_id:card.dataset.id,action:'navigate',input,launch_id:launchId()}});
            const button=event.target.closest('button');if(!button)return;
            const id=button.closest('[data-id]')?.dataset.id;
            if(id&&!button.hasAttribute('data-open'))track({name:'home.feature.activated',props:{widget_id:id,action:'interact',input,launch_id:launchId()}});
            if(button.hasAttribute('data-open'))open(button.dataset.open,button,input);
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

        // Tools stay reachable from one quiet shelf until the Tools page ships. Every mark is the same neutral ink.
        const toolMarks={
            looper:'M4 6h16v12H4z M10 9.5v5l4.5-2.5z','video-downloader':'M12 3v11 M7.5 9.5L12 14l4.5-4.5 M4 16v4h16v-4',sphereseam:'M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18z M3 12h18 M12 3c3 3 3 15 0 18 M12 3c-3 3-3 15 0 18',
            'loop-maker':'M4 12h.01 M8 8v8 M12 4v16 M16 8v8 M20 12h.01','palette-studio':'M3 6h18v12H3z M9 6v12 M15 6v12','sound-effects':'M4 10v4 M8 7v10 M12 4v16 M16 8v8 M20 11v2',
            stylepress:'M12 3a9 9 0 0 0 0 18c1.7 0 2-1.2 1.3-2.2-.8-1.2 0-2.8 1.7-2.8H17a4 4 0 0 0 4-4c0-5-4-9-9-9z M7.5 11h.01 M10 7.5h.01 M14.5 7.5h.01',plaque:'M3 7h18v10H3z M6.5 10.5h3v3h-3z M12 10.5h6 M12 13.5h4',vectorization:'M5 19c0-8 6-14 14-14 M3.5 17.5h3v3h-3z M17.5 3.5h3v3h-3z M5 13V5h8',
        };
        // The Home funnel starts when a tool has been on screen for a second. Tiles are redrawn, so a tool counts once per visit.
        const exposedTools=new Set(),exposureTimers=new Map(),visibleTiles=new Set();
        function armExposure(tile){
            clearTimeout(exposureTimers.get(tile));
            const id=tile.dataset.id;
            if(!visibleTiles.has(tile)||exposedTools.has(id)||document.hidden)return;
            exposureTimers.set(tile,setTimeout(()=>{
                if(!tile.isConnected||!visibleTiles.has(tile)||document.hidden||exposedTools.has(id))return;
                exposedTools.add(id);
                track({name:'home.feature.exposed',props:{widget_id:id,cols:1,rows:1,visible_ms:1000}});
            },1000));
        }
        const exposureObserver=typeof IntersectionObserver==='function'?new IntersectionObserver(entries=>{
            for(const entry of entries){if(entry.isIntersecting&&entry.intersectionRatio>=.5)visibleTiles.add(entry.target);else visibleTiles.delete(entry.target);armExposure(entry.target);}
        },{threshold:.5}):null;
        function forgetTiles(){exposureObserver?.disconnect();exposureTimers.forEach(timer=>clearTimeout(timer));exposureTimers.clear();visibleTiles.clear();}
        document.addEventListener('visibilitychange',()=>visibleTiles.forEach(armExposure),{signal});
        signal.addEventListener('abort',forgetTiles,{once:true});
        function paintTools(){
            const order=['looper','video-downloader','sphereseam','loop-maker','palette-studio','sound-effects','plaque','stylepress','vectorization'],place=id=>(order.indexOf(id)+1)||99;
            const tools=catalog.filter(item=>item.kind==='tool'&&item.id!=='rss'&&item.url).sort((a,b)=>place(a.id)-place(b.id));
            const end=$('[data-tools-end]');end.hidden=!catalog.some(item=>item.id==='calendar');end.parentElement.querySelectorAll('li[data-tool]').forEach(item=>item.remove());
            forgetTiles();
            end.insertAdjacentHTML('beforebegin',tools.map(item=>{const kept=frames.has(item.id);return `<li data-tool><button type="button" class="hm-tool" data-id="${esc(item.id)}" data-open="${esc(item.id)}" data-title="${esc(item.title)}" title="${esc(kept?'Still open. Go back to it':item.description)}"><svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="${toolMarks[item.id]||'M5 5h14v14H5z M9 9h6v6H9z'}"/></svg><span>${esc(item.title)}</span>${kept?'<i class="hm-lamp y" aria-hidden="true"></i>':''}</button></li>`;}).join(''));
            end.parentElement.querySelectorAll('[data-open]').forEach(tile=>exposureObserver?.observe(tile));
        }
        // The iPhone app is the installed web app: its tile opens the three steps.
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
