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
    document.addEventListener('keydown',event=>{if(event.key==='Escape'){notify('escape');}});
    document.addEventListener('DOMContentLoaded',()=>{
        // reportsWork: the listeners above tell Home when work starts, so Home may close this tool
        // without asking while none has been reported.
        notify('ready',{widget:config.widget,reportsWork:true});
    });
    const setTheme=theme=>{
        if(['light','dark'].includes(theme)){document.documentElement.dataset.theme=theme;document.documentElement.style.colorScheme=theme;}
    };
    setTheme(new URLSearchParams(location.search).get('theme'));
    window.addEventListener('message',event=>{
        if(event.source!==window.parent||event.origin!==location.origin||event.data?.protocol!=='mothership-widget/v1')return;
        if(event.data.type==='theme')setTheme(event.data.payload?.theme);
    });
    const originalFetch=window.fetch.bind(window);
    window.fetch=(input,options={})=>{
        const url=new URL(input instanceof Request?input.url:String(input),location.href);
        const method=String(options.method||input?.method||'GET').toUpperCase();
        if(url.origin===location.origin&&!['GET','HEAD','OPTIONS'].includes(method)){const headers=new Headers(options.headers||(input instanceof Request?input.headers:{}));headers.set('X-CSRF-TOKEN',config.csrf);options={...options,headers};}
        return originalFetch(input,options);
    };
    // Legacy Axios widget requests receive the same CSRF protection as the host.
    const originalOpen=XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open=function(method,url,...args){this.__homeWrite=!['GET','HEAD','OPTIONS'].includes(String(method).toUpperCase())&&new URL(url,location.href).origin===location.origin;return originalOpen.call(this,method,url,...args);};
    const originalSend=XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send=function(body){if(this.__homeWrite)this.setRequestHeader('X-CSRF-TOKEN',config.csrf);return originalSend.call(this,body);};
})();
