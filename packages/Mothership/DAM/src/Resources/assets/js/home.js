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
        root.querySelectorAll('[data-slot],[data-day],[data-recent],[data-starred],[data-tools],[data-saved-links]').forEach(slot=>slot.replaceChildren());
        root.querySelectorAll('[data-section]').forEach(section=>{section.hidden=true;});
        root.querySelectorAll('[data-trend-dialog],[data-cal-dialog],[data-access-menu]').forEach(element=>element.remove());
        root.querySelectorAll('dialog[open]').forEach(dialog=>dialog.removeAttribute('open'));
        $('[data-status]').textContent = 'Loading your Home…';
        const sessions = api.sessions();
        const frames = new Map();
        const signalPollers = new Map();
        const signalState = api.signalState();
        let quickView = null;
        let calendar = null;
        const trending = {today:null, days:new Map(), selected:null, loadingDay:null, dayErrors:new Map(), error:null, poller:null};
        // Refresh-button feedback for Trending, separate from the background poller: a click must
        // force github.com/trending through the server-side throttle, never just reread the cache.
        const trendingStatus = {busy:false, message:null, isError:false, timer:null};
        const lifecycle = new AbortController();
        const {signal} = lifecycle;
        let catalog = [], widgets = [], sendState = null, allMine = false;
        const launchId = () => globalThis.crypto?.randomUUID?.() || `home-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
        const track = ({name,props,duration=null}) => {
            try { window.damTrack?.(name,null,props,'home',duration); } catch { /* Analytics never blocks Home. */ }
        };
        const exposures=new Map();
        const exposureObserver=typeof IntersectionObserver==='function'?new IntersectionObserver(entries=>{
            for(const entry of entries){const state=exposures.get(entry.target);if(state){state.visible=entry.isIntersecting&&entry.intersectionRatio>=.5;armExposure(entry.target,state);}}
        },{threshold:.5}):null;
        function armExposure(card,state){
            clearTimeout(state.timer);
            if(state.sent||!state.visible||document.hidden||!card.isConnected)return;
            state.timer=setTimeout(()=>{
                if(!card.isConnected||document.hidden||!state.visible)return;
                state.sent=true;
                track({name:'home.feature.exposed',props:{widget_id:card.dataset.id,cols:Number(card.dataset.columns),rows:Number(card.dataset.rows),visible_ms:1000}});
            },1000);
        }
        function refreshExposures(){exposures.forEach((state,card)=>armExposure(card,state));}
        document.addEventListener('visibilitychange',refreshExposures,{signal});
        const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        const icons = {
            files:'M5 3h9l5 5v13H5z M14 3v6h5 M8 13h8 M8 17h6',
            storage:'M4 5h16v14H4z M4 14h16 M7 17h.01 M10 17h.01',
            library:'M3 5h4v15H3z M10 3h4v17h-4z M17 6l3-1 3 14-3 1z',
            links:'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2 M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2',
            favorite:'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2-4.5-4.4 6.2-.9z',
            rss:'M5 4a15 15 0 0 1 15 15 M5 10a9 9 0 0 1 9 9 M5 17h2v2H5z',
            trending:'M3 17l6-6 4 4 8-8 M15 7h6v6',
            calendar:'M4 6h16v14H4z M4 10h16 M8 3v5 M16 3v5 M8 14h3 M13 14h3 M8 17h3',
            system:'M4 5h16v11H4z M9 20h6 M12 16v4 M7 12l3-3 2 2 4-4',
            stylepress:'M4 4h16v16H4z M4 14l5-5 5 6 3-3 3 3 M15 7h.01',
            looper:'M4 9a8 8 0 0 1 14-3l2 2 M20 3v5h-5 M20 15a8 8 0 0 1-14 3l-2-2 M4 21v-5h5 M10 8l6 4-6 4z',
            'audio-loop':'M3 12h3l2-6 4 12 4-12 2 6h3 M5 4a10 10 0 0 1 15 4 M20 4v4h-4 M19 20a10 10 0 0 1-15-4 M4 20v-4h4',
            palette:'M3 6h18v12H3z M9 6v12 M15 6v12',
            'sound-effects':'M4 10v4 M8 7v10 M12 4v16 M16 8v8 M20 11v2',
            plaque:'M3 7h18v10H3z M6.5 10.5h3v3h-3z M12 10.5h6 M12 13.5h4',
            vectorization:'M4 18C4 5 20 5 20 18 M2 16h4v4H2z M18 16h4v4h-4z M10 2h4v4h-4z M12 6v4',
            sphereseam:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z M12 3c-3 3-3 15 0 18 M12 3c3 3 3 15 0 18 M3 12h18',
        };
        const icon = name => `<span class="agh-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="${icons[name] || icons.files}"/></svg></span>`;
        const bytes = value => { if (value == null) return 'Unavailable'; const units=['B','KB','MB','GB','TB']; let n=Number(value),i=0; while(n>=1024&&i<4){n/=1024;i++;} return `${n.toLocaleString(undefined,{maximumFractionDigits:i>1?1:0})} ${units[i]}`; };
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
            try { const data=await request('/layout',{timeout:20000});if(signal.aborted||!root.isConnected)return;catalog=data.catalog;widgets=data.widgets;render();savedLinks(data.links||[]);status();loadDay();loadRecent(); }
            catch(error){const slow=['TimeoutError','AbortError'].includes(error?.name);status(slow?'Home is taking too long to load.':error.message,true);const retry=document.createElement('button');retry.type='button';retry.className='agh-button';retry.dataset.homeRetry='';retry.textContent='Retry';retry.onclick=()=>{status('Loading your Home…');load();};$('[data-status]').append(' ',retry);}
        }
        function art(id) {
            if(id==='stylepress')return '<div class="agh-tool-art" data-tool="stylepress" aria-hidden="true"><i class="agh-style-swatch"></i><i class="agh-style-swatch"></i><i class="agh-style-swatch"></i></div>';
            if(id==='looper')return '<div class="agh-tool-art" aria-hidden="true"><svg viewBox="0 0 130 66"><path d="M6 35h5l3-12 4 26 5-35 5 42 5-25 5 8 5-17 5 25 5-38 5 47 5-34 5 18 5-5h8 M91 22a15 15 0 1 1-2 21 M89 16v9h9"/></svg></div>';
            if(id==='loop-maker')return '<div class="agh-tool-art" aria-hidden="true"><svg viewBox="0 0 130 66"><path d="M4 33h5l4-14 5 29 5-38 6 46 5-31 6 18 6-26 6 34 6-41 6 47 5-29 6 13 5-8h12 M101 20a15 15 0 1 1-2 23 M99 14v9h9"/></svg></div>';
            if(id==='plaque')return '<div class="agh-tool-art" aria-hidden="true"><svg viewBox="0 0 130 66"><rect x="14" y="18" width="86" height="32" rx="7"/><path d="M22 26h16v16H22z M46 29h40 M46 38h26"/><path opacity=".45" d="M104 34h12 M108 28l8 6-8 6"/></svg></div>';
            if(id==='palette-studio')return '<div class="agh-tool-art" aria-hidden="true"><svg viewBox="0 0 130 66"><path d="M10 10h20v46H10z M34 10h20v46H34z M58 10h20v46H58z M82 10h20v46H82z M106 10h14v46h-14z"/></svg></div>';
            if(id==='sound-effects')return '<div class="agh-tool-art" aria-hidden="true"><svg viewBox="0 0 130 66"><path d="M6 33h6l3-20 4 40 4-32 4 24 4-16 4 9 4-4h8 M68 33h4l3-14 3 28 3-20 3 14 3-8 3 4h6 M102 33h3l2-8 2 16 2-11 2 7 2-4h13"/></svg></div>';
            return '<div class="agh-tool-art" aria-hidden="true"><svg viewBox="0 0 130 66"><rect x="22" y="7" width="86" height="52" rx="5"/><path d="M32 19h35 M32 26h24 M32 39h65 M32 46h52 M78 17h20v13H78z"/></svg></div>';
        }
        // Cards are keyed by widget id: a body is only filled when its card mounts.
        const cards=new Map(),filled=new Map(),fillTokens=new Map(),dataCache=new Map();let fillSequence=0;
        const launchActions = {
            stylepress:'Create with styles', looper:'Refine video loops',
            'loop-maker':'Make audio loops', 'palette-studio':'Build colour palettes', 'sound-effects':'Make sound effects',
            vectorization:'Create vectors', sphereseam:'Repair panorama seams', plaque:'Make overlay plaques',
        };
        const compactPurposes={stylepress:'House styles',looper:'Video loops','loop-maker':'Audio loops','palette-studio':'Colour palettes','sound-effects':'Sound effects',vectorization:'Vector artwork',sphereseam:'Panorama seams',plaque:'Overlay plaques'};
        // The section headings are the h2s; a card names itself in plain words.
        const cardTitles={favorites:'Starred files',rss:'Signal news','system-quickview':'Studio load and who is online',storage:'File storage'};
        function buildCard(widget,definition) {
            const tool=definition.kind==='tool'&&widget.id!=='rss';
            const card=document.createElement('article');card.className='agh-card';card.dataset.id=widget.id;
            const title=cardTitles[widget.id]||definition.title;
            card.innerHTML=`<div class="agh-card-head">${icon(definition.icon)}<h3 title="${esc(title)}">${esc(title)}</h3></div><div class="agh-card-body">${tool?art(widget.id):'<p class="agh-caption">Loading…</p>'}</div>${tool?`<div class="agh-card-footer"><span class="agh-card-tagline" title="${esc(definition.description)}"></span><button class="agh-button" data-open="${esc(widget.id)}" data-title="${esc(definition.title)}"></button></div>`:''}`;
            if(launchActions[widget.id]){
                card.classList.add('agh-launchpad');
                card.querySelector('.agh-card-body').insertAdjacentHTML('afterbegin',`<p class="agh-tool-purpose">${esc(definition.description)}</p><p class="agh-edit-purpose">${esc(compactPurposes[widget.id])}</p>`);
            }
            exposures.set(card,{visible:false,sent:false,timer:null});exposureObserver?.observe(card);
            return card;
        }
        // One fixed page. The agenda, recent work and tool tiles are drawn by this
        // script; only the full calendar (in its dialog) and the studio cards are still cards.
        const admin=root.dataset.superuser==='1';
        const SECTIONS=admin?{calendar:'coming','system-quickview':'status',rss:'status',trending:'status',storage:'status'}:{calendar:'coming'};
        // The density the card styles key on: tools are one-row launchers, everything else is a full card.
        const SHAPES={coming:{columns:8,rows:5},status:{columns:2,rows:2}};
        function syncCard({card,widget,definition}) {
            const launcher=card.querySelector('[data-open]');
            if(launcher){const kept=frames.has(widget.id);card.querySelector('.agh-card-tagline').textContent=kept?'Workspace ready to resume':definition.description;const action=launchActions[widget.id]||'Open';const label=kept?`Resume ${definition.title}`:action;if(launcher.dataset.label!==label){launcher.dataset.label=label;launcher.innerHTML=`<span>${esc(label)}</span> <span aria-hidden="true">↗</span>`;launcher.setAttribute('aria-label',kept?label:`${label} — Open ${launcher.dataset.title}`);}}
        }
        function showSections(){root.querySelectorAll('[data-section]').forEach(section=>{const slot=section.querySelector('[data-slot]');if(slot)section.hidden=!slot.children.length;});}
        function render() {
            for(const id of Object.keys(SECTIONS)) {
                const widget=widgets.find(item=>item.id===id),definition=catalog.find(item=>item.id===id);if(!widget||!definition)continue;
                let card=cards.get(id);
                if(!card){if(filled.has(id))continue;const shape=SHAPES[SECTIONS[id]];card=buildCard(widget,definition);card.dataset.columns=shape.columns;card.dataset.rows=shape.rows;cards.set(id,card);root.querySelector(`[data-slot="${SECTIONS[id]}"]`).append(card);}
                syncCard({card,widget,definition});
            }
            showSections();paintTools();
            for(const widget of widgets) {
                const card=cards.get(widget.id);if(!card||filled.has(widget.id))continue;
                filled.set(widget.id,'1');
                if(catalog.find(item=>item.id===widget.id).kind!=='tool')fillNative({widget,card});
                else if(widget.id==='rss')fillRss(widget,card);
            }
            refreshExposures();
        }
        function age(value) {
            const seconds=Math.max(0,Math.floor((Date.now()-Number(value))/1000));
            if(seconds<60)return 'just now';if(seconds<3600)return `${Math.floor(seconds/60)}m ago`;if(seconds<86400)return `${Math.floor(seconds/3600)}h ago`;return `${Math.floor(seconds/86400)}d ago`;
        }
        function signalKey(widget){return `${widget.settings.feed_id||''}:${widget.settings.count||5}`;}
        // Per-widget transient refresh feedback (busy / result message), independent of the
        // signalState story cache: a click must be visible even when the card is size=1 wide,
        // where CSS used to hide the only element a successful click touched (operator report 2026-09-16).
        const signalStatus = new Map();
        function getSignalStatus(id){if(!signalStatus.has(id))signalStatus.set(id,{busy:false,message:null,isError:false,timer:null});return signalStatus.get(id);}
        function repaintSignal(widget){const live=grid.querySelector(`[data-id="${widget.id}"]`);if(live)paintSignal(widget,live);}
        function paintSignal(widget,card) {
            if(!card.isConnected)return;
            const current=signalState.snapshot();const body=card.querySelector('.agh-card-body');
            const status=getSignalStatus(widget.id);
            if(!current.refreshedAt){body.innerHTML=current.error?`<div class="agh-signal-offline"><p class="agh-signal-state is-offline">Signal is offline. No saved stories are available yet.</p><button class="agh-button" data-signal-refresh${status.busy?' disabled aria-busy="true"':''}>${status.busy?'Refreshing…':'Retry'}</button></div>`:'<p class="agh-caption">Refreshing Signal…</p>';if(!status.busy)body.querySelector('[data-signal-refresh]')?.addEventListener('click',()=>refreshSignal(widget));return;}
            const stories=current.stories||[];const stale=current.error||Date.now()-current.refreshedAt>120000;
            const statusText=status.busy?'':status.message?esc(status.message):'';
            body.innerHTML=`<div class="agh-signal-meta"><span class="${stale?'is-stale':''}">${current.error?'Offline — showing last update':stories.length?'Live feed':'Feed is empty'}</span><span>Updated ${age(current.refreshedAt)}</span><span class="agh-refresh-status${status.isError?' is-stale':''}" data-refresh-status aria-live="polite">${statusText}</span><button class="agh-button" data-signal-refresh${status.busy?' disabled aria-busy="true"':''}>${status.busy?'Refreshing…':'Refresh'}</button></div>${stories.length?`<div class="agh-news">${stories.map(story=>`<a class="agh-news-line" href="${esc(story.url)}" target="_blank" rel="noopener noreferrer"><strong>${esc(story.title)}</strong><small>${esc(story.source||'Signal')} · ${age(story.publishedAt)}</small></a>`).join('')}</div>`:'<p class="agh-caption">No stories are available for this feed. Choose another feed in Settings.</p>'}`;
            if(!status.busy)body.querySelector('[data-signal-refresh]').onclick=()=>refreshSignal(widget);
        }
        function recordSignalFailure(entry,error,widget){if(!signalState.failure({key:entry.key,generation:entry.generation,error}))return;repaintSignal(widget);}
        // A click must show its own result: the poller's success/failure only ever wrote the
        // "Updated …" timestamp, which a rows=1 card hid entirely, so a working refresh looked broken.
        function refreshSignal(widget){
            const entry=signalPollers.get(widget.id);if(!entry)return;
            const status=getSignalStatus(widget.id);if(status.busy)return;
            const before=new Set((signalState.snapshot().stories||[]).map(story=>story.id));
            status.busy=true;status.message=null;clearTimeout(status.timer);repaintSignal(widget);
            entry.refresh().then(()=>{
                const after=signalState.snapshot().stories||[];const added=after.filter(story=>!before.has(story.id)).length;
                status.message=added?`${added} new ${added===1?'story':'stories'}`:'No new stories';status.isError=false;
            }).catch(error=>{
                recordSignalFailure(entry,error,widget);status.message=error?.message||'Refresh failed';status.isError=true;
            }).finally(()=>{
                status.busy=false;repaintSignal(widget);
                status.timer=setTimeout(()=>{status.message=null;repaintSignal(widget);},2000);
            });
        }
        function tickSignal(entry){entry.tick().catch(error=>{const widget=widgets.find(item=>item.id==='rss');if(widget)recordSignalFailure(entry,error,widget);});
        }
        function fillRss(widget,card) {
            const key=signalKey(widget);const generation=signalState.select(key);
            let entry=signalPollers.get(widget.id);
            if(!entry||entry.key!==key){
                const params=new URLSearchParams({limit:String(widget.settings.count||5)});if(widget.settings.feed_id)params.set('feedId',widget.settings.feed_id);
                const poller=api.poller({visible:()=>document.visibilityState!=='hidden'&&widgets.some(item=>item.id===widget.id),request:async()=>{
                    const data=await request(`/service/rss/v1/stories?${params}`);const accepted=signalState.success({key,generation,stories:(data.stories||[]).slice(0,widget.settings.count||5),refreshedAt:Date.now()});
                    const live=grid.querySelector(`[data-id="${widget.id}"]`);if(accepted&&live)paintSignal(widget,live);return data;
                }});
                entry={key,generation,...poller};signalPollers.set(widget.id,entry);
            }
            paintSignal(widget,card);if(!entry.snapshot().lastSuccess)refreshSignal(widget);
        }
        function dayLabel(value){const [year,month,day]=value.split('-').map(Number);return new Date(year,month-1,day).toLocaleDateString(undefined,{month:'short',day:'numeric'});}
        function clockTime(ms){return ms?new Date(ms).toLocaleTimeString([],{hour:'numeric',minute:'2-digit'}):'';}
        function trendingPoller(){
            // The adapter refreshes GitHub every two hours; the browser only rereads its cached day.
            trending.poller??=api.poller({interval:600000,visible:()=>document.visibilityState!=='hidden'&&widgets.some(item=>item.id==='trending'),request:async()=>{
                try{const data=await request('/service/trending/v1/trending');trending.today=data;trending.error=null;return data;}
                catch(error){trending.error=error.message;throw error;}
                finally{paintTrending();}
            }});
            return trending.poller;
        }
        function refreshTrending(){trendingPoller().refresh().catch(()=>{});}
        function tickTrending(){trending.poller?.tick().catch(()=>{});}
        function fillTrending(card){const state=trendingPoller().snapshot();paintTrending(card);if(!state.lastSuccess&&!state.failures)refreshTrending();}
        // The button previously called refreshTrending(), which only rereads the adapter's cache —
        // GitHub is fetched at most once per two hours in the background, so a click could sit
        // there with nothing to show for up to two hours (operator report 2026-09-16). This forces
        // the adapter's own rate-limited fetch via ?refresh=1 instead.
        function forceRefreshTrending(){
            if(trendingStatus.busy)return;
            trendingStatus.busy=true;trendingStatus.message=null;clearTimeout(trendingStatus.timer);paintTrending();
            request('/service/trending/v1/trending?refresh=1').then(data=>{
                trending.today=data;
                // A forced fetch that failed comes back non-2xx (see server.py) and lands in .catch()
                // below, never here. This branch only ever sees 200s, but a *throttled* call still
                // rereads whatever is cached, which can itself be a stale error snapshot — that must
                // never be reported as "Fresh" either (operator/Codex review 2026-09-16).
                if(data.status==='error'){
                    trendingStatus.message='GitHub is still unreachable.';trendingStatus.isError=true;
                }else{
                    trending.error=null;
                    const minutes=Math.max(1,Math.round((Number(data.throttledUntil)-Date.now())/60000));
                    trendingStatus.message=data.forced?'Refreshed':`Fresh as of ${clockTime(data.refreshedAt)}, next check in ${minutes} min`;
                    trendingStatus.isError=false;
                }
            }).catch(error=>{
                trending.error=error.message;trendingStatus.message=error.message;trendingStatus.isError=true;
            }).finally(()=>{
                trendingStatus.busy=false;paintTrending();
                trendingStatus.timer=setTimeout(()=>{trendingStatus.message=null;paintTrending();},2000);
            });
        }
        async function showTrendingDay(date){
            trending.selected=date||null;
            if(date&&!trending.days.has(date)){
                trending.dayErrors.delete(date);trending.loadingDay=date;paintTrending();
                try{trending.days.set(date,await request(`/service/trending/v1/trending/${encodeURIComponent(date)}`));}
                catch(error){trending.dayErrors.set(date,error.message);}
                finally{if(trending.loadingDay===date)trending.loadingDay=null;}
            }
            paintTrending();
        }
        function paintTrending(card=grid.querySelector('[data-id="trending"]')){
            if(!card?.isConnected)return;
            const body=card.querySelector('.agh-card-body');const today=trending.today;
            if(!today){const busy=trendingStatus.busy;const statusText=!busy&&trendingStatus.message?esc(trendingStatus.message):'';body.innerHTML=trending.error?`<p class="agh-signal-state is-offline">Trending is offline. No saved repositories are available yet.</p><button class="agh-button" data-trending-refresh${busy?' disabled aria-busy="true"':''}>${busy?'Retrying…':'Retry'}</button><p class="agh-refresh-status${trendingStatus.isError?' is-stale':''}" data-refresh-status aria-live="polite">${statusText}</p>`:'<p class="agh-caption">Loading trending repositories…</p>';if(!busy)body.querySelector('[data-trending-refresh]')?.addEventListener('click',forceRefreshTrending);return;}
            const selected=trending.selected;const view=selected?trending.days.get(selected):today;
            const past=(today.days||[]).filter(day=>day.date!==today.date);const recent=past.slice(0,6),older=past.slice(6);
            const stale=trending.error||['error','stale'].includes(today.status);
            const refreshStatusText=trendingStatus.busy?'':trendingStatus.message?esc(trendingStatus.message):'';
            const refreshButton=`<span class="agh-refresh-status${trendingStatus.isError?' is-stale':''}" data-refresh-status aria-live="polite">${refreshStatusText}</span><button class="agh-button" data-trending-refresh${trendingStatus.busy?' disabled aria-busy="true"':''}>${trendingStatus.busy?'Refreshing…':'Refresh'}</button>`;
            const meta=selected?`<span>${view?`${view.repos.length} ${view.repos.length===1?'repository':'repositories'} trended on ${esc(dayLabel(selected))}`:esc(dayLabel(selected))}</span>`:`<span class="${stale?'is-stale':''}">${trending.error?'Offline — showing last update':stale?'GitHub unreachable — showing last update':today.repos.length?'Trending now':'Waiting for the first update'}</span>${today.updatedAt?`<span>Updated ${age(today.updatedAt)}</span>`:''}${refreshButton}`;
            const days=`<div class="agh-trend-days" role="group" aria-label="Trending day"><button class="agh-trend-day" data-trend-day="" aria-pressed="${!selected}">Today</button>${recent.map(day=>`<button class="agh-trend-day" data-trend-day="${esc(day.date)}" aria-pressed="${selected===day.date}">${esc(dayLabel(day.date))}</button>`).join('')}${older.length?`<select class="agh-trend-older" data-trend-older aria-label="Older trending days"><option value="">Older…</option>${older.map(day=>`<option value="${esc(day.date)}"${selected===day.date?' selected':''}>${esc(dayLabel(day.date))}</option>`).join('')}</select>`:''}</div>`;
            let list;
            if(selected&&trending.loadingDay===selected)list='<p class="agh-caption">Loading…</p>';
            else if(selected&&!view)list=`<p class="agh-caption">${esc(trending.dayErrors.get(selected)||'This day is not available.')}</p>`;
            else list=view.repos.length?`<ol class="agh-trend-list">${view.repos.map((repo,index)=>`<li><button class="agh-trend-row${!selected&&!repo.onList?' is-off':''}" data-trend-repo="${index}"><span class="agh-trend-rank">${index+1}</span><span class="agh-trend-text"><strong>${esc(repo.repo)}</strong><small>${esc(repo.description||'No description provided.')}</small></span>${repo.starsToday!=null?`<span class="agh-trend-stars">★ ${Number(repo.starsToday).toLocaleString()}</span>`:''}</button></li>`).join('')}</ol>`:`<p class="agh-caption">${selected?'No repositories were recorded that day.':'No repositories yet today. The list updates every two hours.'}</p>`;
            body.innerHTML=`<div class="agh-signal-meta">${meta}</div>${days}${list}${!selected&&!past.length?'<p class="agh-caption">Earlier days appear here after midnight.</p>':''}`;
            if(!trendingStatus.busy)body.querySelector('[data-trending-refresh]')?.addEventListener('click',forceRefreshTrending);
            body.querySelectorAll('[data-trend-day]').forEach(button=>button.addEventListener('click',()=>showTrendingDay(button.dataset.trendDay)));
            body.querySelector('[data-trend-older]')?.addEventListener('change',event=>{if(event.target.value)showTrendingDay(event.target.value);});
            body.querySelectorAll('[data-trend-repo]').forEach(button=>button.addEventListener('click',()=>trendingPopup({repo:view.repos[Number(button.dataset.trendRepo)],opener:button,live:!selected})));
        }
        function trendingPopup({repo,opener,live}){
            if(!repo)return;
            let dialog=root.querySelector('[data-trend-dialog]');
            if(!dialog){dialog=document.createElement('dialog');dialog.className='agh-settings agh-trend-dialog';dialog.dataset.trendDialog='';root.append(dialog);dialog.addEventListener('close',()=>{if(dialog.opener?.isConnected)dialog.opener.focus();});}
            const url=/^https:\/\/github\.com\/[\w.-]+\/[\w.-]+$/.test(repo.url)?repo.url:`https://github.com/${repo.repo}`;
            const facts=[repo.language,repo.stars!=null?`★ ${Number(repo.stars).toLocaleString()} stars`:null,repo.starsToday!=null?`+${Number(repo.starsToday).toLocaleString()} today`:null].filter(Boolean);
            const seen=`Best rank #${repo.bestRank??repo.rank} · seen trending ${clockTime(repo.firstSeenAt)}${repo.lastSeenAt&&repo.lastSeenAt!==repo.firstSeenAt?`–${clockTime(repo.lastSeenAt)}`:''}${live&&!repo.onList?' · no longer on the list':''}`;
            dialog.innerHTML=`<form method="dialog"><div class="agh-dialog-head"><div><p class="agh-eyebrow">TRENDING ON GITHUB</p><h2>${esc(repo.repo)}</h2></div><button class="agh-button" value="close" aria-label="Close repository details">✕</button></div><p class="agh-trend-description">${esc(repo.description||'No description provided.')}</p>${facts.length?`<p class="agh-caption">${facts.map(esc).join(' · ')}</p>`:''}<p class="agh-caption">${esc(seen)}</p><div class="agh-actions"><button class="agh-button" value="close">Close</button><a class="agh-button agh-primary" href="${esc(url)}" target="_blank" rel="noopener noreferrer">Open on GitHub <span aria-hidden="true">↗</span></a></div></form>`;
            dialog.opener=opener;dialog.showModal();
        }
        // One instance for the page: a rerender hands it the new card instead of starting a second poller.
        function mountQuickView(card){
            const factory=window.MothershipSystemQuickView;
            if(!factory){card.querySelector('.agh-card-body').innerHTML='<p class="agh-caption">Studio load is unavailable right now.</p>';return;}
            quickView??=factory.create({root,request,intervalMs:10000,now:()=>Date.now(),reducedMotion:window.matchMedia?.('(prefers-reduced-motion: reduce)').matches===true});
            quickView.mount(card);
        }
        async function fillNative({widget,card}) {
            const body=card.querySelector('.agh-card-body');
            // Cards outlive a settings change, so a slow older request must not paint over a newer one.
            // A token per fill, never the settings themselves: settings can return to an earlier value.
            const token=++fillSequence;fillTokens.set(widget.id,token);const current=()=>card.isConnected&&fillTokens.get(widget.id)===token;
            if(widget.id==='trending'){fillTrending(card);return;}
            if(widget.id==='system-quickview'){mountQuickView(card);return;}
            if(widget.id==='calendar'){const instance=calendarApi();if(!instance){body.innerHTML='<p class="agh-caption">The calendar is unavailable right now.</p>';return;}instance.mount(card);return;}
            try {
                const path=widget.id==='favorites'?`/favorites?limit=${widget.settings.count||6}`:`/data/${widget.id}${widget.id==='recent'?`?limit=${widget.settings.limit||6}`:''}`;
                const data=dataCache.get(path)??await request(path);
                if(!current())return;
                dataCache.set(path,data);
                if(widget.id==='docs-count') {
                    body.textContent=`${Number(data.articles)} documentation articles available to your role`;
                    return;
                }
                if(['recent','favorites'].includes(widget.id)) {
                    body.innerHTML=data.assets.length?`<div class="agh-recent">${data.assets.map(asset=>`<a class="agh-asset" href="${esc(asset.url)}" aria-label="${esc(asset.name)}" title="${esc(asset.name)}"><span class="agh-thumb"><img src="${esc(asset.thumbnail)}" loading="lazy" alt="" /></span><span class="agh-asset-text"><strong>${esc(asset.name)}</strong><small>${esc(asset.type_label||asset.type||'File')} · ${asset.created_at?esc(new Date(asset.created_at).toLocaleDateString()):''}</small></span></a>`).join('')}</div>`:'';
                    if(widget.id==='recent'&&!data.assets.length)body.innerHTML=`<div class="agh-empty-note"><strong>No files here yet.</strong><p>Files you and your team add show up here, newest first.</p>${root.querySelector('[data-start="upload"]')?'<button type="button" class="agh-button agh-primary" data-start="upload">Upload your first files</button>':''}</div>`;
                    // Starred files only take room once there are some; until then one line says how to pin a file.
                    if(widget.id==='favorites'&&!data.assets.length){card.remove();cards.delete(widget.id);const slot=root.querySelector('[data-slot="recent"]');if(!slot.querySelector('[data-star-tip]'))slot.insertAdjacentHTML('beforeend','<p class="agh-section-note" data-star-tip>Tip: star a file in the Library and it stays pinned here.</p>');showSections();return;}
                    body.querySelectorAll('img').forEach(img=>img.addEventListener('error',()=>{img.parentElement.innerHTML=icon('files');},{once:true}));
                } else if(widget.id==='storage') {
                    body.innerHTML=data.available?`<div class="agh-number">${bytes(data.free)}</div><div class="agh-caption">free space left for files</div><div class="agh-meter" role="meter" aria-label="Storage used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round((1-data.free/data.total)*100)}"><span style="width:${Math.max(0,Math.min(100,(1-data.free/data.total)*100))}%"></span></div><div class="agh-caption">${bytes(data.total-data.free)} used of ${bytes(data.total)}</div>`:'<p class="agh-caption" data-store-message></p>';
                    if(!data.available)body.querySelector('[data-store-message]').textContent=data.message||'Available capacity is not reported by this storage provider.';
                } else {
                    body.innerHTML=`<div class="agh-number">${Number(data.files).toLocaleString()}</div><div class="agh-caption">files you can open</div><div class="agh-stats"><div><strong>${Number(data.folders).toLocaleString()}</strong><span>folders</span></div><div><strong>${bytes(data.bytes)}</strong><span>library size</span></div></div>`;
                }
            } catch(error) { if(!current())return;body.innerHTML=`<p class="agh-caption">${esc(error.message)}</p><button class="agh-button" data-retry aria-label="Retry ${esc(catalog.find(item=>item.id===widget.id)?.title||'widget')}">Retry</button>`;body.querySelector('[data-retry]').onclick=()=>fillNative({widget,card}); }
        }
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
            exposureObserver?.disconnect();exposures.forEach(state=>clearTimeout(state.timer));exposures.clear();
            // The mount replaced this root with a fresh one (start ran ahead of it): start that one.
            afterMount();
        },{signal});
        root.addEventListener('click',event=>{
            const input=event.detail===0?'keyboard':'pointer';
            const link=event.target.closest('a');const card=event.target.closest('[data-id]');
            if(link&&card)track({name:'home.feature.activated',props:{widget_id:card.dataset.id,action:'navigate',input,launch_id:launchId()}});
            const start=event.target.closest('[data-start-key]');
            if(start)track({name:'home.feature.activated',props:{widget_id:`start-${start.dataset.startKey}`,action:start.tagName==='A'?'navigate':'interact',input,launch_id:launchId()}});
            const button=event.target.closest('button');if(!button)return;
            const id=button.closest('[data-id]')?.dataset.id;
            if(id&&!button.hasAttribute('data-open'))track({name:'home.feature.activated',props:{widget_id:id,action:'interact',input,launch_id:launchId()}});
            if(button.hasAttribute('data-open'))open(button.dataset.open,button,input);
            if(button.dataset.startOpen)open(button.dataset.startOpen,button,input);
            if(button.dataset.start==='upload')openUpload(button);
            if(button.dataset.start==='send'){if(sendState?.open)sendState.open(null);else location.assign(button.dataset.startUrl);}
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
        const signalTimer=setInterval(()=>{signalPollers.forEach(tickSignal);tickTrending();},15000);
        signal.addEventListener('abort',()=>clearInterval(signalTimer),{once:true});
        signal.addEventListener('abort',()=>{quickView?.unmount();quickView=null;},{once:true});
        document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'){signalPollers.forEach(tickSignal);tickTrending();}},{signal});
        // ---- The fixed page's own parts: greeting, saved links, what needs attention, and upload. ----
        const firstName=String(root.dataset.name||'').trim().split(/\s+/)[0]||'';
        const hour=new Date().getHours();
        $('[data-greeting]').textContent=`Good ${hour<12?'morning':hour<17?'afternoon':'evening'}${firstName?`, ${firstName}`:''}`;
        $('[data-home-search]')?.closest('form').addEventListener('submit',()=>track({name:'home.feature.activated',props:{widget_id:'start-search',action:'navigate',input:'keyboard',launch_id:launchId()}}),{signal});
        function calendarApi(){const factory=window.MothershipHomeCalendar;if(!factory)return null;calendar??=factory.create({root,request,esc});return calendar;}
        // Quick links a person saved while Home was customizable stay reachable, read-only.
        function savedLinks(links){
            const section=root.querySelector('[data-section="links"]');
            section.hidden=!links.length;
            $('[data-saved-links]').innerHTML=links.map(link=>`<a href="${esc(link.url)}"${new URL(link.url,location.origin).origin!==location.origin?' target="_blank" rel="noopener noreferrer"':''}>${esc(link.title)} <span aria-hidden="true">↗</span></a>`).join('');
        }
        const pad=number=>String(number).padStart(2,'0');
        const localDay=(date=new Date())=>`${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())}`;
        const dayText=value=>{const [year,month,day]=String(value).slice(0,10).split('-').map(Number);return new Date(year,month-1,day).toLocaleDateString([],{weekday:'short',month:'short',day:'numeric'});};
        function dueFlag(task){
            const today=localDay();
            if(!task.due_on)return {text:'No due date',tone:''};
            if(task.due_on<today)return {text:`Overdue · was due ${dayText(task.due_on)}`,tone:'is-late'};
            if(task.due_on===today)return {text:'Due today',tone:'is-today'};
            return {text:`Due ${dayText(task.due_on)}`,tone:''};
        }
        const stampDate=stamp=>{const [d,t='00:00']=String(stamp).split('T');const [y,m,day]=d.split('-').map(Number);const [h,min]=t.split(':').map(Number);return new Date(y,m-1,day,h||0,min||0);};
        const clock=stamp=>stampDate(stamp).toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});
        const dayName=day=>{const tomorrow=new Date();tomorrow.setDate(tomorrow.getDate()+1);return day===localDay()?'Today':day===localDay(tomorrow)?'Tomorrow':dayText(day);};
        $('[data-today]').textContent=new Date().toLocaleDateString([],{weekday:'long',month:'long',day:'numeric'});
        // "Your day": the tasks given to this person (overdue first), then what is on the calendar over the next two
        // weeks, one list. The month grid is one button away, in its own dialog.
        async function loadDay(){
            const section=root.querySelector('[data-section="day"]'),slot=$('[data-day]');
            if(!catalog.some(item=>item.id==='calendar')){section.hidden=true;return;}
            section.hidden=false;
            if(!slot.children.length)slot.innerHTML='<p class="agh-caption">Loading your day…</p>';
            try{
                // A campaign that began earlier and is still running belongs on today's list, so the range starts before today.
                const from=new Date();from.setDate(from.getDate()-60);const to=new Date();to.setDate(to.getDate()+15);
                const [tasks,range]=await Promise.all([request('/calendar/mine'),request(`/calendar/events?from=${localDay(from)}&to=${localDay(to)}`)]);
                if(signal.aborted||!root.isConnected)return;
                const today=localDay();
                const mine=[...(tasks.mine||[])].sort((a,b)=>(a.due_on||'9999-99-99').localeCompare(b.due_on||'9999-99-99'));
                // The lists stop at 50 rows; the server counts all of them, so the sentence stays right past that.
                const totals=tasks.totals||{};
                const total=Number(totals.mine??mine.length),late=Number(totals.overdue??mine.filter(task=>task.due_on&&task.due_on<today).length);
                // Tasks on coming events that nobody has.
                const open=Number(totals.unassigned??(tasks.unassigned||[]).length);
                const live=(range.events||[]).filter(event=>event.status==='confirmed');
                const running=live.filter(event=>String(event.starts_at).slice(0,10)<today&&event.ends_at&&String(event.ends_at).slice(0,10)>=today).slice(0,2);
                const coming=live.filter(event=>String(event.starts_at).slice(0,10)>=today).sort((a,b)=>String(a.starts_at).localeCompare(String(b.starts_at))).slice(0,7);
                const shown=allMine?mine:mine.slice(0,5);
                const taskRow=task=>{
                    const flag=dueFlag(task);
                    return `<li><button type="button" class="agh-attn-row" data-attn-event="${Number(task.event.id)}"><span class="agh-attn-flag ${flag.tone}">${esc(flag.text)}</span><span class="agh-attn-text"><strong title="${esc(task.title)}">${esc(task.title)}</strong><small title="${esc(task.event.title)}">For ${esc(task.event.title)} · ${esc(dayText(task.event.starts_at))}</small></span><span class="agh-attn-go" aria-hidden="true">Open ›</span></button></li>`;
                };
                const eventRow=(event,label)=>`<li><button type="button" class="agh-attn-row" data-attn-event="${Number(event.id)}"><span class="agh-attn-flag">${esc(label)}</span><span class="agh-attn-text"><strong title="${esc(event.title)}">${esc(event.title)}</strong>${event.location?`<small title="${esc(event.location)}">${esc(event.location)}</small>`:''}</span>${event.unassigned?`<span class="agh-attn-go">${Number(event.unassigned)} ${event.unassigned===1?'task needs':'tasks need'} someone</span>`:'<span class="agh-attn-go" aria-hidden="true">›</span>'}</button></li>`;
                const groups=[];
                // Past the 50 rows the server sends, the list says how many it holds instead of promising all of them.
                const more=total-shown.length,capped=total>mine.length;
                groups.push(mine.length?`<h3 class="agh-agenda-head is-you">Waiting on you</h3><ul class="agh-attn-list">${shown.map(taskRow).join('')}</ul>${more>0?`<p class="agh-attn-more"><span>${allMine?`The first ${mine.length} of your ${total} tasks are listed.`:`${more} more ${more===1?'task is':'tasks are'} yours.`}</span>${allMine?'':` <button type="button" class="agh-button" data-attn-all>${capped?`Show the first ${mine.length}`:'Show all my tasks'}</button>`}</p>`:''}`
                    :'<p class="agh-agenda-clear"><strong>Nothing is waiting on you.</strong> When someone gives you a task for an event, it shows up here.</p>');
                if(running.length)groups.push(`<h3 class="agh-agenda-head">Happening now</h3><ul class="agh-attn-list">${running.map(event=>eventRow(event,`Until ${dayText(event.ends_at)}`)).join('')}</ul>`);
                const days=[...new Set(coming.map(event=>String(event.starts_at).slice(0,10)))];
                for(const day of days)groups.push(`<h3 class="agh-agenda-head">${esc(dayName(day))}${dayName(day)!==dayText(day)?` <small>${esc(dayText(day))}</small>`:''}</h3><ul class="agh-attn-list">${coming.filter(event=>String(event.starts_at).slice(0,10)===day).map(event=>eventRow(event,event.all_day?'All day':clock(event.starts_at))).join('')}</ul>`);
                if(!coming.length&&!running.length)groups.push('<p class="agh-agenda-clear">Nothing is on the calendar for the next two weeks.</p>');
                slot.innerHTML=groups.join('')
                    +(open?`<p class="agh-attn-more"><span><strong>${open} ${open===1?'task':'tasks'}</strong> on coming events ${open===1?'has':'have'} nobody yet.</span> <button type="button" class="agh-button" data-attn-filter="open">See tasks that need someone</button></p>`:'')
                    +'<p class="agh-agenda-foot"><button type="button" class="agh-button agh-primary" data-calendar-open>Open the full calendar</button><span>See the whole month, add an event or give someone a task.</span></p>';
                const next=coming[0];
                const waiting=total?`${total} ${total===1?'task is':'tasks are'} waiting on you${late?`, ${late} overdue`:''}.`:'Nothing is waiting on you.';
                const sentences=[waiting,next?`Next on the calendar: ${next.title}, ${dayName(String(next.starts_at).slice(0,10)).toLowerCase()}${next.all_day?'':` at ${clock(next.starts_at)}`}.`:''].filter(Boolean);
                $('[data-brief]').textContent=sentences.join(' ');
            }catch(error){
                if(signal.aborted||!root.isConnected)return;
                slot.innerHTML=`<p class="agh-caption">${esc(error.message)}</p><button type="button" class="agh-button" data-attn-retry>Try again</button>`;
            }
        }
        const calendarDialog=$('[data-calendar-dialog]');
        function showCalendar(filter){const api=calendarApi();if(!api)return;if(filter)api.show(filter);if(!calendarDialog.open)calendarDialog.showModal();}
        $('[data-day]').addEventListener('click',event=>{
            const button=event.target.closest('button');if(!button)return;
            if(button.hasAttribute('data-attn-retry'))loadDay();
            // Every task, here in the list: the month grid only shows one month.
            if(button.hasAttribute('data-attn-all')){allMine=true;loadDay();}
            if(button.dataset.attnEvent)calendarApi()?.open(Number(button.dataset.attnEvent));
            if(button.dataset.attnFilter)showCalendar(button.dataset.attnFilter);
            if(button.hasAttribute('data-calendar-open'))showCalendar('all');
        },{signal});
        calendarDialog.addEventListener('click',event=>{if(event.target.closest('[data-calendar-close]'))calendarDialog.close();},{signal});
        // A task ticked off or taken, or an event added, changes the list: reread when either dialog closes.
        root.addEventListener('close',event=>{if(event.target.matches?.('[data-cal-dialog],[data-calendar-dialog]'))loadDay();},{capture:true,signal});

        // Recent work, large enough to recognise a file by its picture. Starred files sit under it once there are some.
        async function loadRecent(){
            const section=root.querySelector('[data-section="recent"]'),slot=$('[data-recent]'),starred=$('[data-starred]');
            if(!catalog.some(item=>item.id==='recent')){section.hidden=true;return;}
            section.hidden=false;
            const tile=asset=>`<a class="agh-work-item" href="${esc(asset.url)}" title="${esc(asset.name)}"><span class="agh-work-thumb"><img src="${esc(asset.thumbnail)}" alt="" /></span><strong>${esc(asset.name)}</strong><small>${esc(asset.type_label||asset.type||'File')}${asset.created_at?` · added ${esc(age(new Date(asset.created_at).getTime()))}`:''}</small></a>`;
            try{
                const data=await request('/data/recent?limit=8');if(signal.aborted||!root.isConnected)return;
                slot.innerHTML=data.assets.length?data.assets.map(tile).join(''):`<div class="agh-empty-note"><strong>No files here yet.</strong><p>Files you and your team add show up here, newest first.</p>${root.querySelector('[data-start-key=upload]')?'<button type="button" class="agh-button agh-primary" data-start="upload">Upload your first files</button>':''}</div>`;
            }catch(error){if(signal.aborted||!root.isConnected)return;slot.innerHTML=`<p class="agh-caption">${esc(error.message)}</p>`;}
            if(!catalog.some(item=>item.id==='favorites')){starred.replaceChildren();return;}
            try{
                const data=await request('/favorites?limit=6');if(signal.aborted||!root.isConnected)return;
                starred.innerHTML=data.assets.length?`<h3 class="agh-agenda-head">Starred files</h3><div class="agh-starred-list">${data.assets.map(asset=>`<a href="${esc(asset.url)}" title="${esc(asset.name)}"><img src="${esc(asset.thumbnail)}" alt="" /><span>${esc(asset.name)}</span></a>`).join('')}</div>`:'<p class="agh-section-note">Tip: star a file in the Library and it stays pinned here.</p>';
            }catch{starred.replaceChildren();}
        }
        // Studio tools as app icons: the product name under the icon, what it makes as the small line.
        const appIcons={
            looper:{tone:'#0f766e',path:'M4 6h16v12H4z M10 9.5v5l4.5-2.5z'},
            'video-downloader':{tone:'#be185d',path:'M12 3v11 M7.5 9.5L12 14l4.5-4.5 M4 16v4h16v-4'},
            sphereseam:{tone:'#0369a1',path:'M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18z M3 12h18 M12 3c3 3 3 15 0 18 M12 3c-3 3-3 15 0 18'},
            'loop-maker':{tone:'#b45309',path:'M4 12h.01 M8 8v8 M12 4v16 M16 8v8 M20 12h.01'},
            'palette-studio':{tone:'#a21caf',path:'M3 6h18v12H3z M9 6v12 M15 6v12'},
            'sound-effects':{tone:'#9a3412',path:'M4 10v4 M8 7v10 M12 4v16 M16 8v8 M20 11v2'},
            stylepress:{tone:'#7e22ce',path:'M12 3a9 9 0 0 0 0 18c1.7 0 2-1.2 1.3-2.2-.8-1.2 0-2.8 1.7-2.8H17a4 4 0 0 0 4-4c0-5-4-9-9-9z M7.5 11h.01 M10 7.5h.01 M14.5 7.5h.01'},
            plaque:{tone:'#c2410c',path:'M3 7h18v10H3z M6.5 10.5h3v3h-3z M12 10.5h6 M12 13.5h4'},
            vectorization:{tone:'#15803d',path:'M5 19c0-8 6-14 14-14 M3.5 17.5h3v3h-3z M17.5 3.5h3v3h-3z M5 13V5h8'},
        };
        const appPurposes={looper:'Smooth video loops','video-downloader':'Save a video from a link',sphereseam:'Fix 360° photo seams','loop-maker':'Seamless audio loops','palette-studio':'Colour palettes','sound-effects':'Game-style sound effects',stylepress:'Art in house styles',plaque:'Animated video overlays',vectorization:'Photos into vectors'};
        function paintTools(){
            const order=['looper','video-downloader','sphereseam','loop-maker','palette-studio','sound-effects','plaque','stylepress','vectorization'],place=id=>(order.indexOf(id)+1)||99;
            const tools=catalog.filter(item=>item.kind==='tool'&&item.id!=='rss'&&item.url).sort((a,b)=>place(a.id)-place(b.id));
            root.querySelector('[data-section="apps"]').hidden=false;
            const end=$('[data-tools-end]');end.parentElement.querySelectorAll('li:not([data-tools-end])').forEach(item=>item.remove());
            end.insertAdjacentHTML('beforebegin',tools.map(item=>{const kept=frames.has(item.id),mark=appIcons[item.id]||{tone:'#374151',path:'M5 5h14v14H5z M9 9h6v6H9z'};return `<li><button type="button" class="agh-app" data-id="${esc(item.id)}" data-open="${esc(item.id)}" data-title="${esc(item.title)}" title="${esc(item.description)}"><span class="agh-app-icon" style="--agh-app:${mark.tone}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="${mark.path}"/></svg>${kept?'<i class="agh-app-open" aria-hidden="true"></i>':''}</span><strong>${esc(item.title)}</strong><small>${esc(kept?'Still open. Go back to it':appPurposes[item.id]||item.description)}</small></button></li>`;}).join(''));
        }
        // The iPhone app is the installed web app: its tile opens the three steps.
        const phoneDialog=$('[data-phone-dialog]');let phoneOpener=null;
        root.addEventListener('click',event=>{
            const opener=event.target.closest('[data-phone-open]');
            if(opener){phoneOpener=opener;if(!phoneDialog.open)phoneDialog.showModal();}
            if(event.target.closest('[data-phone-close]'))phoneDialog.close();
        },{signal});
        phoneDialog.addEventListener('close',()=>{if(phoneOpener?.isConnected)phoneOpener.focus();},{signal});

        // Send files to a person: whoever has The Mothership open right now, each with one Send button.
        // The Transfers runtime hands its state, with open() and openPeer(), to each subscriber.
        const sendSlot=root.querySelector('[data-send]');
        if(sendSlot){
            let sendKey=null;
            const paintSend=state=>{
                sendState=state;
                if(!root.isConnected)return;
                const peers=state?.readyPeers||[],labels=state?.labels||{};
                const key=JSON.stringify([state?.status,peers,state?.activeCount]);if(key===sendKey)return;sendKey=key;
                const busy=state?.activeCount?`<p class="agh-send-active" role="status">${esc((state.activeCount===1?labels.activeOne:labels.active||'').replace(':count',state.activeCount))}</p>`:'';
                if(!state||state.status!=='ready'){sendSlot.innerHTML=`${busy}<p class="agh-caption" role="status">${esc(state?(labels[state.status]||labels.unavailable||'Transfers is unavailable right now.'):'Looking for people who are online…')}</p>`;return;}
                sendSlot.innerHTML=busy+(peers.length?`<ul class="agh-send-list">${peers.slice(0,5).map(peer=>`<li><span class="agh-send-face" aria-hidden="true">${esc(String(peer.name||'?').trim().charAt(0).toUpperCase())}</span><span class="agh-send-who"><strong title="${esc(peer.name)}">${esc(peer.name)}</strong><small title="${esc(peer.deviceName)}">${esc(peer.deviceName)}</small></span><button type="button" class="agh-button agh-primary" data-send-peer="${esc(peer.peerId)}" aria-label="${esc((labels.sendToDevice||'Send files to :name on :device').replace(':name',peer.name).replace(':device',peer.deviceName))}">Send files</button></li>`).join('')}</ul>${peers.length>5?`<p class="agh-section-note">${peers.length-5} more online. Open Transfers to see everyone.</p>`:''}`:'<div class="agh-empty-note"><strong>Nobody else is online right now.</strong><p>Ask the person to open The Mothership. They appear here as soon as they do, and you can send to your own phone or second computer the same way.</p></div>');
            };
            const release=window.MothershipTransfers?.subscribe?.(paintSend);
            if(!release)sendSlot.innerHTML='<p class="agh-caption">Open Transfers to send or receive files.</p>';
            signal.addEventListener('abort',()=>release?.(),{once:true});
            sendSlot.addEventListener('click',event=>{const button=event.target.closest('[data-send-peer]');if(button)sendState?.openPeer?.(button.dataset.sendPeer);},{signal});
        }

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
                uploadDialog.innerHTML=`${head}<div class="agh-empty-note" role="status"><strong>${upload.done.count} ${upload.done.count===1?'file is':'files are'} in “${esc(upload.done.folder)}”.</strong><p>They also appear under “Pick up where you left off”.</p><div class="agh-actions"><button type="button" class="agh-button" data-upload-again>Upload more</button><a class="agh-button agh-primary" href="${esc(root.dataset.library)}">See them in the Library</a></div></div>`;
            }else{
                uploadDialog.innerHTML=`${head}<ol class="agh-steps">
                    <li><strong>1. Choose the folder they go in</strong>${picking
                        ?`<label>Find a folder<input type="search" data-upload-folder value="${esc(upload.term)}" placeholder="Type part of a folder name" autocomplete="off" /></label>${upload.folders===null?'<p class="agh-caption">Loading folders…</p>':upload.folders.length?`<div class="agh-upload-folders">${upload.folders.map(item=>`<button type="button" class="agh-button" data-upload-pick="${Number(item.id)}" data-name="${esc(folderName(item))}">${esc(folderName(item))}</button>`).join('')}</div>`:`<p class="agh-caption">${upload.term.trim().length>=2?'No folder has that name. Try fewer letters.':'There are no folders you can upload to yet. Ask an administrator to share one with you.'}</p>`}`
                        :`<p class="agh-upload-folder"><span>Folder: <b>${esc(folder.name)}</b></span> <button type="button" class="agh-button" data-upload-change>Choose a different folder</button></p>`}</li>
                    <li><strong>2. Choose the files</strong>${picking?'<p class="agh-caption">Pick a folder first.</p>':`<label class="agh-button agh-primary agh-upload-choose"${upload.busy?' aria-disabled="true"':''}>${upload.busy?esc(upload.busy):'Choose files from this computer'}<input type="file" multiple hidden data-upload-files ${upload.busy?'disabled':''} /></label>`}</li>
                </ol>${upload.error?`<p class="agh-signal-state is-offline" role="alert">${esc(upload.error)}</p>`:''}`;
            }
            if(keepFocus){const again=uploadDialog.querySelector('[data-upload-folder]');again?.focus();again?.setSelectionRange(again.value.length,again.value.length);}
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
                // The new files belong at the top of Recent files.
                loadRecent();
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
        await load();
    };
    // Vue mounts synchronously on DOMContentLoaded (and after SPA script injection). Start on the next task.
    const afterMount=()=>setTimeout(start,0);
    // The Admin app mounts on DOMContentLoaded; waiting for `load` instead left Home empty while any font or image stalled.
    // After SPA injection readyState is already `interactive` (DOMContentLoaded fired long ago) while a stalled subresource holds `load`.
    if(document.readyState!=='loading')afterMount();else{document.addEventListener('DOMContentLoaded',afterMount,{once:true});window.addEventListener('load',afterMount,{once:true});}
})();
