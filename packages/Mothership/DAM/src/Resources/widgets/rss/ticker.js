(function (root) {
    'use strict';
    const DAY = 86400000, REFRESH = 300000;
    const providers = {'Anthropic Direct':'Anthropic','Google DeepMind Direct':'Google','Higgsfield Direct':'Higgsfield','OpenAI News':'OpenAI','Anthropic (GNews)':'Anthropic','Google DeepMind (GNews)':'Google','Google AI':'Google','Higgsfield (GNews)':'Higgsfield','Tibo (thsottiaux)':'Codex'};
    const provider = name => providers[name] || null;
    function canonical(value) {
        try {
            const url = new URL(value);
            if (!['https:', 'http:'].includes(url.protocol)) return null;
            url.hash = '';
            for (const key of [...url.searchParams.keys()]) if (/^(utm_|fbclid|gclid)/i.test(key)) url.searchParams.delete(key);
            return url.href;
        } catch (_) { return null; }
    }
    function select({stories, now}) {
        const seen = new Set();
        return stories.flatMap(story => {
            const label = provider(story.source), url = canonical(story.url), title = String(story.title || '');
            if (!label || !url || !Number.isFinite(story.publishedAt) || story.publishedAt < now - 7 * DAY || story.publishedAt > now + 300000) return [];
            const host = new URL(url).hostname;
            const hosts = {OpenAI:['openai.com'],Anthropic:['anthropic.com','news.google.com'],Google:['blog.google','deepmind.google','news.google.com'],Higgsfield:['higgsfield.ai','news.google.com'],Codex:['x.com','twitter.com']};
            if (!hosts[label].some(h => host === h || host.endsWith('.' + h))) return [];
            if (label === 'Codex' && !/^\/thsottiaux\/status\/\d+\/?$/i.test(new URL(url).pathname)) return [];
            // Scoped to his own status URLs (checked above), so any reset wording counts. Feed text
            // carries curly apostrophes, so match any n't contraction with either apostrophe.
            const reset = label === 'Codex' && /\breset(?:s|ting)?\b/i.test(title) && !/(?:\b(?:no|not|never|nothing|none|cannot)\b|n['’]t\b).{0,25}\breset/i.test(title);
            // After "ChatGPT" a loose integer is almost always a count or duration ("3 ways to",
            // "5-day"), so its version must be hyphen-attached or decimal. "GPT-6" and
            // "gpt-image-2.5" keep the plain digit form the feeds have always used.
            const namedModel = /\b(chatgpt(?:[ -](?:images?|audio|realtime|vision))?(?:-\d|[ -]\d+\.\d)|gpt(?:[ -](?:images?|audio|realtime|vision))?[ -]?\d|o[134](?:[ -]|\b)|claude(?:[ -]+[a-z]+){0,2}[ -]+\d|(?:opus|sonnet|haiku)[ -]?\d|gemini[ -]?\d|veo[ -]?\d|imagen[ -]?\d|sora[ -]?\d|flux[ -]?\d|minimax[ -]?h\d|kling[ -]?\d|recraft[ -]?v\d|genjutsu|soul[ -]?\d)\b/i;
            const directModelTitle = /^(GPT[ -]?\d|Claude(?:[ -]+[a-z]+){0,2}[ -]+\d|Gemini[ -]?\d|Veo[ -]?\d|Imagen[ -]?\d|Sora[ -]?\d)\b/i.test(title);
            const launch = /\b(introducing|introduce[sd]?|launch(?:es|ed|ing)?|releas(?:e[sd]?|ing)|announc(?:e[sd]?|ing)|unveil(?:s|ed)?|now available|can now|now on|rolling out|rolls out|meet|new features?)\b/i.test(title) || directModelTitle || /^Try\s/i.test(title) || /^Higgsfield [^:]{1,45}:\s*(Build|Create|Generate|Animate)/i.test(title);
            const noise = /\b(best|top \d+|how to|tutorial|pricing|unlimited|subscription|fellowship|partnership|roundup|rumou?rs?)\b/i.test(title);
            if (label === 'Codex' ? !/\b(codex|chatgpt work)\b/i.test(title) && !reset : (!launch || noise)) return [];
            const model = namedModel.test(title) || /\bmodels?\b/i.test(title);
            if (seen.has(url)) return [];
            seen.add(url);
            return [{id:String(story.id),title,url,publishedAt:story.publishedAt,source:story.source,provider:label,alert:reset?'reset':model?'model':null}];
        }).sort((a,b) => b.publishedAt-a.publishedAt);
    }
    function advance({previous, stories, now}) {
        const valid = previous && previous.version === 1 && Array.isArray(previous.seen) && Array.isArray(previous.unread);
        const seen = new Set(valid ? previous.seen : []), fresh = [];
        const baseline = valid ? previous.baseline : now;
        for (const story of stories) {
            const key = canonical(story.url);
            if (valid && !seen.has(key) && story.alert && story.publishedAt > baseline && story.publishedAt >= now-7*DAY) fresh.push(story);
            seen.add(key);
        }
        const unread = [...(valid ? previous.unread : []),...fresh].filter((s,i,a) => a.findIndex(x=>canonical(x.url)===canonical(s.url))===i).slice(-50);
        return {fresh,state:{version:1,baseline,seen:[...seen].slice(-1000),unread,items:stories,checkedAt:now}};
    }
    const api = {provider,select,advance};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (!root.document) return;
    root.MothershipSignal = api;
    if (root.__mothershipSignalBoot) return;
    root.__mothershipSignalBoot = true;
    let cleanup = () => {};
    function mount() {
        const el = document.querySelector('[data-signal-ticker]');
        if (!el || el.dataset.mounted) return;
        el.dataset.mounted = '1';
        const lifecycle = new AbortController();
        const signal = lifecycle.signal;
        const key = 'mothership-signal-v1:' + el.dataset.user;
        const read = () => { try { return JSON.parse(localStorage.getItem(key)); } catch (_) { return null; } };
        let state = read(), items = [], cursor = 0, busy = false, failureAt = 0, toastTimer;
        const get = name => el.querySelector('[data-signal-'+name+']');
        const headline = get('headline'), badge = get('badge'), panel = get('panel'), list = get('list'), status = get('status'), toast = get('toast');
        const save = () => { try { localStorage.setItem(key,JSON.stringify(state)); } catch (_) {} };
        function links(target, rows) {
            target.replaceChildren();
            for (const story of rows) {
                const url = canonical(story.url); if (!url) continue;
                const a = document.createElement('a'); a.href=url; a.target='_blank'; a.rel='noopener noreferrer';
                a.textContent = story.provider + ' · ' + story.title;
                target.append(a);
            }
        }
        function renderSources() {
            const sources = state?.sources || [];
            const healthy = sources.filter(s=>s.status==='ok' && Date.now()-(s.lastSuccessAt||0)<=1800000);
            const issues = sources.filter(s=>!healthy.includes(s));
            get('health').textContent = state?.connectionError ? 'Sources offline' : sources.length ? 'Sources '+healthy.length+'/'+sources.length : 'Checking sources';
            get('health').title = issues.map(s=>s.label+': '+s.status).join(' · ');
            get('health').dataset.degraded = String(state?.connectionError || issues.length>0 || !sources.length);
            get('sources').replaceChildren();
            for (const source of sources) {
                const row=document.createElement('div');
                const checked=source.lastSuccessAt ? ' · checked '+Math.max(0,Math.floor((Date.now()-source.lastSuccessAt)/60000))+'m ago' : '';
                row.textContent=source.label+': '+(healthy.includes(source)?'OK':source.status==='ok'?'stale':source.status)+checked+(source.detail?' — '+source.detail:'');
                get('sources').append(row);
            }
            status.textContent=state?.connectionError ? 'Feed connection unavailable · showing last updates' : 'Direct sources checked every 15 minutes. Alerts cover the last 7 days.';
        }
        function render() {
            items = (state?.items || []).filter(s=>s.publishedAt >= Date.now()-7*DAY);
            const slots = Math.min(items.length,innerWidth>=1400?3:innerWidth>=900?2:1);
            headline.replaceChildren();
            for (let slot=0;slot<slots;slot++) {
                const story=items[(cursor+slot)%items.length];
                const item=document.createElement('div');item.className='signal-item';
                links(item,[story]);
                const age = document.createElement('time');
                const hours = Math.max(0,Math.floor((Date.now()-story.publishedAt)/3600000));
                age.textContent = hours < 1 ? 'just now' : hours < 24 ? hours+'h' : Math.floor(hours/24)+'d';
                age.dateTime = new Date(story.publishedAt).toISOString(); item.append(age);headline.append(item);
            }
            if (!slots) headline.textContent = 'No recent announcements';
            renderSources();
            const unread = state?.unread || [];
            badge.textContent = unread.length; badge.hidden = !unread.length;
            get('bell').setAttribute('aria-label','Announcement alerts'+(unread.length ? ', '+unread.length+' unread':''));
            links(list,unread.slice().reverse());
            if (!unread.length) list.textContent='You’re caught up. New model releases and Codex reset announcements appear here.';
        }
        async function fetchJSON(url) {
            const response = await fetch(url,{signal:AbortSignal.any([signal,AbortSignal.timeout(20000)]),headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('Feed unavailable');
            return response.json();
        }
        async function refresh() {
            if (busy || document.hidden || signal.aborted) return;
            const cached = read(); if (cached?.version===1) state=cached;
            render();
            if (Date.now()-Math.max(state?.sources ? state.checkedAt||0 : 0,failureAt)<REFRESH) return;
            busy=true;
            try {
                const work = async () => {
                    if (signal.aborted) return;
                    const latest = read(); if (latest?.sources && latest.checkedAt && Date.now()-latest.checkedAt<REFRESH) { state=latest;render();return; }
                    const data = await fetchJSON(el.dataset.api+'/v1/announcements');
                    if (!Array.isArray(data.stories) || !Array.isArray(data.sources)) throw new Error('Invalid announcement response');
                    if (signal.aborted) return;
                    const next = advance({previous:state,stories:select({stories:data.stories,now:Date.now()}),now:Date.now()});
                    state={...next.state,sources:data.sources,connectionError:false}; save();render();
                    if (next.fresh.length) {
                        links(toast,next.fresh.slice(0,2)); toast.hidden=false;
                        clearTimeout(toastTimer); toastTimer=setTimeout(()=>{toast.hidden=true;},10000);
                    }
                };
                if (navigator.locks) await navigator.locks.request(key,work); else await work();
            } catch (_) { if (!signal.aborted) { failureAt=Date.now();state={...state,connectionError:true};save();render(); } }
            finally { busy=false; }
        }
        get('bell').onclick=()=>{panel.hidden=!panel.hidden;get('bell').setAttribute('aria-expanded',String(!panel.hidden));};
        get('health').onclick=()=>{panel.hidden=false;get('bell').setAttribute('aria-expanded','true');};
        get('read').onclick=()=>{state=read()||state;if(state){state.unread=[];save();render();}};
        document.addEventListener('keydown',e=>{if(e.key==='Escape'){panel.hidden=true;get('bell').setAttribute('aria-expanded','false');}},{signal});
        document.addEventListener('click',e=>{if(!el.contains(e.target)){panel.hidden=true;get('bell').setAttribute('aria-expanded','false');}},{signal});
        window.addEventListener('storage',e=>{if(e.key===key){state=read();render();}},{signal});
        document.addEventListener('visibilitychange',refresh,{signal});
        window.addEventListener('resize',render,{signal});
        const rotation = setInterval(()=>{if(!document.hidden&&!el.matches(':hover')&&!el.contains(document.activeElement)&&!matchMedia('(prefers-reduced-motion: reduce)').matches){cursor++;render();}},8000);
        const polling = setInterval(refresh,60000);
        cleanup = () => {lifecycle.abort();clearInterval(rotation);clearInterval(polling);clearTimeout(toastTimer);delete el.dataset.mounted;};
        render();refresh();
    }
    document.addEventListener('mothership:navigate:before',()=>cleanup());
    document.addEventListener('mothership:navigate:success',mount);
    // The host Vue app replaces its template on window load; bind to its final DOM.
    if (document.readyState==='complete') setTimeout(mount,0); else window.addEventListener('load',()=>setTimeout(mount,0),{once:true});
})(typeof window==='undefined'?globalThis:window);
