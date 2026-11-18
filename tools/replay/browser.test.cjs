const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const https = require('node:https');
const net = require('node:net');
const zlib = require('node:zlib');
const {spawn, execFileSync} = require('node:child_process');
const {pathToFileURL} = require('node:url');
const root = path.resolve(__dirname, '../..');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH || path.join(root, 'tests/e2e-pw/node_modules/playwright');
const playwright = require(playwrightPath);
const port = () => new Promise(resolve => {const server = net.createServer(); server.listen(0, '127.0.0.1', () => {const p = server.address().port; server.close(() => resolve(p));});});
const waitFor = async (predicate, message) => {const end = Date.now()+15000; while(Date.now()<end) {if (await predicate()) return; await new Promise(r=>setTimeout(r,100));} throw Error(typeof message==='function'?message():message);};
function decode(body, url) {
  if (new URL(url, 'https://localhost').searchParams.get('compression') === 'gzip-js' || (body[0]===31 && body[1]===139)) return JSON.parse(zlib.gunzipSync(body));
  const text = body.toString();
  if (text.startsWith('data=')) return JSON.parse(new URLSearchParams(text).get('data'));
  return JSON.parse(text);
}
function unpack(value) {
  if(typeof value==='string' && value.charCodeAt(0)===31 && value.charCodeAt(1)===139) return unpack(JSON.parse(zlib.gunzipSync(Buffer.from(value,'latin1'))));
  if(Array.isArray(value))return value.map(unpack);
  if(value&&typeof value==='object')return Object.fromEntries(Object.entries(value).map(([k,v])=>[k,unpack(v)]));
  return value;
}
test('real recorder sends masked navigable snapshots through authenticated CSRF relay and links current sessions', {timeout:120000}, async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'mothership-replay-'));
  const fixtureDatabase=path.join(temp,'fixture.sqlite');
  fs.copyFileSync(process.env.DB_DATABASE || path.join(root,'database/replay-test.sqlite'),fixtureDatabase);
  let browser, php, upstream;
  const uploads=[];
  try {
    execFileSync('openssl', ['req','-x509','-newkey','rsa:2048','-nodes','-keyout',path.join(temp,'key'),'-out',path.join(temp,'cert'),'-days','1','-subj','/CN=localhost'], {stdio:'ignore'});
    upstream = https.createServer({key:fs.readFileSync(path.join(temp,'key')),cert:fs.readFileSync(path.join(temp,'cert'))}, (req,res)=> {
      const chunks=[]; req.on('data',c=>chunks.push(c)); req.on('end',()=> {
        const data=Buffer.concat(chunks); res.setHeader('Content-Type','application/json');
        if(req.url.startsWith('/flags/')||req.url.startsWith('/array/')) res.end(JSON.stringify({sessionRecording:{endpoint:'/s/',sampleRate:1,minimumDurationMilliseconds:0,consoleLogRecordingEnabled:true,networkPayloadCapture:{recordHeaders:true,recordBody:true}},capturePerformance:true,featureFlags:{},hasFeatureFlags:false}));
        else {uploads.push({headers:req.headers,data:unpack(decode(data,req.url))}); res.end('{}');}
      });
    });
    await new Promise(r=>upstream.listen(0,'127.0.0.1',r));
    const appPort=await port(); const origin=`http://127.0.0.1:${appPort}`;
    php=spawn('php',['-S',`127.0.0.1:${appPort}`,'-t','public','tools/replay/fixture.php'],{cwd:root,env:{...process.env,APP_ENV:'testing',APP_URL:origin,DB_CONNECTION:'sqlite',DB_DATABASE:fixtureDatabase,REPLAY_UPSTREAM:`https://127.0.0.1:${upstream.address().port}`},stdio:['ignore','ignore','pipe']});
    let errors=''; php.stderr.on('data',d=>errors+=d);
    await waitFor(async()=>{try{return (await fetch(origin+'/admin/home')).ok;}catch{return false;}},()=> 'fixture failed: '+errors.slice(-2000));
    if(process.env.MOTHERSHIP_CHROME_LAUNCHER) {
      const {launchChrome}=await import(pathToFileURL(process.env.MOTHERSHIP_CHROME_LAUNCHER));
      browser=await launchChrome({chromium:playwright.chromium,playwrightVersion:require(path.join(playwrightPath,'package.json')).version});
    } else browser=await playwright.chromium.launch({headless:true});
    const eligibleUserAgent='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';
    const eligibleBrowser=()=>{
      Object.defineProperty(navigator,'webdriver',{get:()=>false});
      // headless_shell also advertises HeadlessChrome in client hints. Exercise
      // the eligible browser path without changing the SDK's production bot filter.
      Object.defineProperty(navigator,'userAgentData',{get:()=>undefined});
    };
    const browserContext=await browser.newContext({userAgent:eligibleUserAgent});
    await browserContext.addInitScript(eligibleBrowser);
    // Shift only Date for idle rotation. Playwright's clock API also replaces
    // timer/performance APIs, which this actual-recorder probe must leave native.
    await browserContext.addInitScript(()=>{
      const NativeDate=Date;window.fixtureClockOffset=0;
      window.Date=new Proxy(NativeDate,{
        construct(target,args,newTarget){return Reflect.construct(target,args.length?args:[target.now()+window.fixtureClockOffset],newTarget);},
        apply(target){return new target(target.now()+window.fixtureClockOffset).toString();},
        get(target,key){return key==='now'?()=>target.now()+window.fixtureClockOffset:Reflect.get(target,key);}
      });
    });
    const page=await browserContext.newPage(); const failures=[],warnings=[]; page.on('pageerror',e=>failures.push(e.message));
    page.on('console',message=>{if(warnings.length<50&&['warning','error'].includes(message.type()))warnings.push(message.text().slice(0,200));});
    const responses=[]; page.on('response',r=>{if(r.url().includes('/analytics/replay/'))responses.push({url:r.url(),status:r.status()});});
    const diagnostic=message=>()=>message+': '+JSON.stringify({responses,failures,warnings,browser:browser.version(),uploads:uploads.length})+' '+errors.slice(-2000);
    await page.goto(origin+'/admin/home?private=Private-url-sentinel');
    await waitFor(()=>page.evaluate(()=>window.damReplay?.recording()),diagnostic('recorder did not start'));
    assert.equal(await page.locator('meta[name="csrf-token"]').count(),0,'real Admin layout must use its cookie path');
    const first=await page.evaluate(()=>window.damReplay.sessionId()); assert.match(first,/^[0-9a-f-]{36}$/);
    await page.evaluate(()=>{window.fixtureDocument='original';window.fixtureVisits=0;document.addEventListener('mothership:navigate:success',()=>window.fixtureVisits++);});
    const softVisit=async destination=>{
      const previous=await page.evaluate(()=>window.fixtureVisits);
      await page.locator(`nav[data-replay-safe] a[href="/admin/${destination}"]`).click();
      await page.waitForFunction(previous=>window.fixtureVisits>previous,previous);
      assert.equal(page.url(),origin+'/admin/'+destination);
    };
    await page.locator('#mothership-action').click();
    await page.evaluate(()=>{const node=document.getElementById('mothership-action');node.setAttribute('title','Private mutation sentinel');node.setAttribute('style','width:30px;background:url(/Private-mutation-sentinel.png);--private:"Private mutation sentinel"');});
    await page.mouse.move(10,10);
    await page.locator('input:not([type])').fill('Private input sentinel');
    await waitFor(()=>uploads.length>0,diagnostic('no real snapshot uploads'));
    const recordedEvents=uploads.flatMap(upload=>(Array.isArray(upload.data)?upload.data:[upload.data]))
      .flatMap(event=>event.properties.$snapshot_data).sort((a,b)=>a.timestamp-b.timestamp);
    const embeddedSheets=[];
    function findSheets(node){if(node&&typeof node==='object'){if(node.attributes?._cssText)embeddedSheets.push(node);Object.values(node).forEach(findSheets);}}
    recordedEvents.forEach(findSheets);
    assert.ok(embeddedSheets.some(node=>node.attributes._cssText.includes('.flex-col')&&!node.attributes.href),
      'uploaded snapshots must embed Admin CSS without a fetch fallback');
    const denyThemeCss=route=>route.abort();
    await page.route('**/themes/**/*.css',denyThemeCss);
    const playback=await page.evaluate(async events=>{
      const {Replayer}=await import('/runtime/rrweb.js');
      const root=document.createElement('div');root.dataset.replayBlock='';document.body.append(root);
      const player=new Replayer(events,{root,showWarning:false,showDebug:false,loadTimeout:0,mouseTail:false});
      await new Promise(resolve=>player.on('fullsnapshot-rebuilded',resolve));
      player.pause(events.at(-1).timestamp-events[0].timestamp);
      const doc=player.iframe.contentDocument;const app=doc.getElementById('app');
      const result={display:getComputedStyle(app).display,direction:getComputedStyle(app).flexDirection,
        height:app.getBoundingClientRect().height,bodyOverflow:getComputedStyle(doc.body).overflow};
      player.destroy();root.remove();return result;
    },recordedEvents);
    await page.unroute('**/themes/**/*.css',denyThemeCss);
    assert.equal(playback.display,'flex','actual replay must retain Admin flex layout');
    assert.equal(playback.direction,'column');assert.ok(playback.height>500);assert.equal(playback.bodyOverflow,'hidden');
    const step=await page.evaluate(()=>fetch('/probe').then(r=>r.json())); assert.equal(step.session,first);
    assert.equal(step.exported_session,first,'actual recorder id must reach the canonical outbox');
    const axiosStep=await page.evaluate(()=>axios.post('/probe',{}).then(r=>r.data));
    assert.equal(axiosStep.session,first); assert.equal(axiosStep.exported_session,first);
    await page.evaluate(()=>{const p=document.createElement('p'); p.dataset.replaySafe=''; p.textContent='Final navigation marker'; document.body.append(p);});
    await softVisit('library');
    await page.waitForFunction(()=>document.querySelector('h1[data-replay-safe]')?.textContent==='Library');
    assert.equal(await page.locator('#dam-t').count(),1,'destination config must retain its stable id');
    assert.equal(await page.evaluate(()=>window.fixtureDocument),'original','must exercise a soft visit, not a reload');
    await waitFor(()=>page.evaluate(()=>window.damReplay?.recording()),diagnostic('library recording not started'));
    assert.equal(await page.evaluate(()=>window.damReplay.sessionId()),first,'normal navigation retains actual session');
    await page.locator('#mothership-action').click();
    await waitFor(()=>JSON.stringify(uploads).includes('Library')&&JSON.stringify(uploads).includes('Final navigation marker'),diagnostic('navigation snapshot missing'));
    for (const destination of ['search','home']) {
      await softVisit(destination);
      await page.waitForFunction(()=>document.querySelectorAll('#dam-t').length===1&&window.damReplay?.recording());
      assert.equal(await page.evaluate(()=>window.fixtureDocument),'original');
      assert.equal(await page.evaluate(()=>window.damReplay.sessionId()),first);
      const linked=await page.evaluate(()=>fetch('/probe').then(r=>r.json()));
      assert.equal(linked.exported_session,first);
    }
    const payload=JSON.stringify(uploads.map(u=>u.data));
    for(const secret of ['Private asset sentinel','Private input sentinel','Private hidden sentinel','Private editable sentinel','Private-url-sentinel','Private title sentinel','Private attribute sentinel','Private-attribute-sentinel','Private iframe sentinel','Private image sentinel','Private mutation sentinel','Private-mutation-sentinel','Private CSS sentinel','Private-css-url-sentinel','Private-css-query-sentinel','Private-css-fragment-sentinel','Private CSS query content sentinel','Private CSS chunk content sentinel','Private CSS lookalike content sentinel','Private CSS alternate sentinel','mount-fixture.css','app-lookalike.css'])assert.ok(!payload.includes(secret),secret+' leaked');
    assert.ok(payload.includes('Home')&&payload.includes('Library'));
    const batches=uploads.flatMap(u=>Array.isArray(u.data)?u.data:[u.data]);
    assert.ok(batches.length); assert.ok(batches.every(e=>e.event==='$snapshot'),'canonical product capture duplicated');
    assert.ok(batches.every(e=>e.properties.$session_id===first));
    assert.ok(batches.every(e=>e.properties.distinct_id===step.distinct_id),'recorder/exporter pseudonyms differ');
    const geometry=[];function findGeometry(node){if(node&&typeof node==='object'){if(node.attributes?.rr_width)geometry.push(node.attributes);Object.values(node).forEach(findGeometry);}}
    batches.forEach(findGeometry);assert.ok(geometry.some(a=>parseFloat(a.rr_width)>0&&parseFloat(a.rr_height)>0),'blocked placeholder geometry was lost');
    assert.ok(uploads.every(u=>!u.headers.cookie&&!u.headers.authorization&&!u.headers['x-csrf-token']));
    assert.ok(responses.every(r=>r.status===200),JSON.stringify(responses));
    let foreignHeaders;
    await page.route('https://other.example/probe',route=>{foreignHeaders=route.request().headers();return route.fulfill({status:200,headers:{'Access-Control-Allow-Origin':'*'},body:'{}'});});
    await page.evaluate(()=>fetch('https://other.example/probe'));
    assert.ok(!foreignHeaders['x-dam-replay-session'],'session id crossed an origin');
    await page.evaluate(()=>axios.get('https://other.example/probe'));
    assert.ok(!foreignHeaders['x-dam-replay-session'],'axios session id crossed an origin');
    // Date is controlled before module initialization, including SDK/rrweb timestamps.
    await page.evaluate(()=>window.fixtureClockOffset+=31*60*1000);
    await page.locator('#mothership-action').click();
    const rotated=await page.evaluate(()=>window.damReplay.sessionId());
    assert.notEqual(rotated,first,'SDK idle rotation must be read dynamically');
    const rotatedStep=await page.evaluate(()=>axios.post('/probe',{}).then(r=>r.data));
    assert.equal(rotatedStep.session,rotated); assert.equal(rotatedStep.exported_session,rotated);
    await page.evaluate(()=>document.getElementById('adminLogout').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
    assert.equal(await page.evaluate(()=>window.damReplay.sessionId()),null);
    await page.evaluate(()=>{const node=document.getElementById('dam-t');const c=JSON.parse(node.textContent);c.m='new-auth-session';c.replay.distinct_id='b'.repeat(64);node.textContent=JSON.stringify(c);document.dispatchEvent(new Event('mothership:navigate:success'));});
    await waitFor(()=>page.evaluate(()=>window.damReplay?.recording()),diagnostic('next account recorder did not start'));
    assert.notEqual(await page.evaluate(()=>window.damReplay.sessionId()),rotated,'next account reused prior replay session');
    await softVisit('no-replay');
    await page.waitForFunction(()=>!window.damReplay?.recording());
    assert.equal(await page.locator('#dam-t').count(),0,'destination denial must remove previous config');
    assert.equal(await page.evaluate(()=>window.damReplay.sessionId()),null);
    const deniedStep=await page.evaluate(()=>fetch('/probe').then(r=>r.json()));
    assert.equal(deniedStep.session,null,'denied destination must not retain previous actor linkage');
    const context=await browser.newContext({userAgent:eligibleUserAgent});
    await context.addInitScript(eligibleBrowser);
    await context.addInitScript(()=>Object.defineProperty(navigator,'globalPrivacyControl',{get:()=>sessionStorage.getItem('fixtureGpc')==='1'}));
    const privatePage=await context.newPage();const beforeControl=uploads.length;
    await privatePage.goto(origin+'/admin/home');await waitFor(()=>privatePage.evaluate(()=>window.damReplay?.recording()),diagnostic('GPC positive control failed to initialize'));
    await privatePage.locator('#mothership-action').click();await waitFor(()=>uploads.length>beforeControl,diagnostic('GPC positive control emitted no real snapshot'));
    await privatePage.evaluate(()=>fetch('/probe').then(r=>r.json()));
    await privatePage.evaluate(()=>sessionStorage.setItem('fixtureGpc','1'));
    let gpcRequests=0;privatePage.on('request',request=>{if(request.url().includes('/analytics/replay/'))gpcRequests++;});
    const beforeGpc=uploads.length;await privatePage.goto(origin+'/admin/home');
    assert.equal(await privatePage.evaluate(()=>window.damReplay.sessionId()),null);
    await privatePage.waitForTimeout(2500); // Covers the SDK's observed 2-second normal flush window.
    assert.equal(gpcRequests,0,'client-only GPC sent relay traffic');assert.equal(uploads.length,beforeGpc,'GPC uploaded snapshots');await context.close();
    console.log(JSON.stringify({event:'replay.browser.proof',browser:browser.version(),snapshots:batches.length,playback,navigation:true,csrf:true,masked:true,attributes:true,mutation:true,geometry:true,logout:true,gpc:true,gpcPositiveControl:true,rotation:true,crossUser:true,sameOrigin:true,axios:true,canonicalExport:true}));
  } finally {if(browser)await browser.close(); if(php)php.kill(); if(upstream)await new Promise(r=>upstream.close(r));fs.rmSync(temp,{recursive:true,force:true});}
});
