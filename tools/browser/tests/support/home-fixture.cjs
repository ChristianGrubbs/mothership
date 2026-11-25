'use strict';
// The fixed Home page's root markup for browser tests. It mirrors
// packages/Mothership/DAM/src/Resources/views/home/index.blade.php: keep the two in step, so a test
// never carries its own copy of the page.

const fs=require('node:fs');
const path=require('node:path');

const assets=path.resolve(__dirname,'../../../../packages/Mothership/DAM/src/Resources/assets');
const attribute=value=>String(value).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');

// Home's scripts in the order the page loads them. home.js starts the page, so it comes last.
const homeScripts=['js/home-state.js','js/home-signal.js','js/home-bridge.js','js/home.js'].map(file=>fs.readFileSync(path.join(assets,file),'utf8'));
const homeStyles=fs.readFileSync(path.join(assets,'css/home.css'),'utf8');

/**
 * @param {object} [options]
 * @param {boolean} [options.superuser] full-access account: data-superuser="1", which turns on Signal
 * @param {boolean} [options.library] the account may open the Library
 * @param {string} [options.tag] root element name
 * @param {Object<string,string|null>} [options.attrs] extra or replaced root attributes; null drops one
 */
function homeRoot({superuser=false,library=true,tag='div',attrs={}}={}){
    const rootAttributes={
        'data-base':'/admin/home','data-csrf':'fixture','data-user':'1','data-name':'Test',
        'data-library':'/admin/dam','data-upload':'/admin/dam/assets/upload',
        'data-directory-search':'/admin/dam/directory/search','data-directories':'/admin/dam/directory',
        'data-library-allowed':library?'1':'0','data-superuser':superuser?'1':'0',
        'data-connect':'/admin/integrations','data-shares':'/admin/shares','data-requests':'/admin/requests','data-new-uploads':'/admin/new-uploads',...attrs,
    };
    const attributeText=Object.entries(rootAttributes).filter(([,value])=>value!==null).map(([name,value])=>`${name}="${attribute(value)}"`).join(' ');
    return `<${tag} data-mothership-home ${attributeText}>`
        +'<h1 class="hm-sr">Home</h1><div role="status" aria-live="polite" class="hm-status" data-status></div><div role="status" aria-live="polite" class="hm-stale" data-stale></div>'
        +'<div class="hm-layout" data-grid><div class="hm-centre">'
        +'<div class="hm-object" data-board-object><section class="hm-board" data-board aria-labelledby="hm-board-title" aria-busy="true"><div class="hm-board-head hm-mono"><h2 id="hm-board-title">Arrivals board</h2><span class="hm-sp"></span><span class="hm-board-state" data-board-state></span></div><div role="list" aria-label="Arrivals since your last visit" data-board-rows></div><p class="hm-more hm-mono" data-board-more hidden></p></section><section class="hm-deck" data-deck aria-label="New since your last visit" aria-busy="true"></section></div>'
        +'<nav class="hm-card hm-glance" aria-label="Around the ship" data-glance></nav>'
        +'<section class="hm-card hm-needs" data-needs aria-label="Needs you" hidden></section>'
        +'<div data-cargo-section hidden><div class="hm-sec" id="hm-cargo"><h2>Cargo</h2><span class="hm-mono">What landed, newest first</span><span class="hm-sec-acts"><button type="button" class="hm-sec-link is-quiet" data-calendar-open hidden><svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v14H4z M4 10h16 M8 3v5 M16 3v5"/></svg>Calendar</button>'+(library?'<a class="hm-sec-link" href="/admin/dam/assets?scope=all&amp;recent=1">Open in Library <svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h10v10M7 17 17 7"/></svg></a>':'')+'</span></div><ol class="hm-log" data-cargo></ol><p class="hm-empty hm-cargo-empty" data-cargo-empty hidden>Nothing has landed in your Library yet.</p></div>'
        +'</div><aside class="hm-side" aria-label="Around the ship" data-side>'
        +'<section class="hm-card hm-dock" id="hm-docked" data-docked><div data-docked-body></div><p class="hm-foot"><button type="button" class="hm-foot-link" data-id="phone-app" data-phone-open>Add your iPhone</button></p></section>'
        +'<section class="hm-card hm-signal" id="hm-signal" data-signal data-id="rss"></section>'
        +'<section class="hm-card hm-away" id="hm-away" data-away><div data-away-body></div><div class="hm-foot" data-section="links" hidden><h3 class="hm-mono">Your saved links</h3><div class="hm-saved-links" data-saved-links></div></div><p class="hm-foot" data-phone-elsewhere hidden><button type="button" class="hm-foot-link" data-id="phone-app" data-phone-open>Add your iPhone</button></p></section></aside></div>'
        +'<dialog class="hm-sheet" data-side-sheet aria-labelledby="hm-sheet-title"><div class="hm-sheet-head"><h2 id="hm-sheet-title">Around the ship</h2><button type="button" class="hm-btn" data-sheet-close>Close</button></div><div data-sheet-body></div></dialog>'
        +'<dialog class="agh-settings agh-calendar-dialog" data-calendar-dialog aria-labelledby="agh-calendar-title"><div class="agh-dialog-head"><h2 id="agh-calendar-title">Calendar</h2><button type="button" class="agh-button" data-calendar-close aria-label="Close calendar">✕</button></div><div class="agh-slot" data-slot="coming"><article class="agh-card" data-id="calendar"><div class="agh-card-body"></div></article></div></dialog>'
        +'<dialog class="agh-settings agh-upload" data-upload-dialog aria-labelledby="agh-upload-title"></dialog>'
        +'<dialog class="agh-settings agh-phone" data-phone-dialog aria-labelledby="agh-phone-title"><div class="agh-dialog-head"><h2 id="agh-phone-title">The Mothership on your iPhone</h2><button type="button" class="agh-button" data-phone-close aria-label="Close">✕</button></div><div class="agh-phone-body"><div><p class="agh-section-note">There is nothing to download from the App Store. Add The Mothership to your Home Screen and it opens like any other app.</p><ol class="agh-phone-steps"><li>On your iPhone or iPad, open <b>mothership.test</b> in Safari and sign in.</li><li>Tap <b>Share</b> at the bottom of the screen.</li><li>Tap <b>Add to Home Screen</b>, then <b>Add</b>.</li></ol></div></div></dialog>'
        +`</${tag}>`;
}

// The root inside the admin shell's #app, which Home marks inert while a tool is open.
const homeApp=options=>`<div id="app">${homeRoot(options)}</div>`;

// Page script: window.__homeAnswer(address) gives an empty, well-formed body for every endpoint the
// fixed page reads on its own, or null for anything else. A test's fetch stub answers what it cares
// about and falls back to this, so no part of the page meets a body it cannot read.
const homeAnswersSource=`window.__homeSummary=(extra={})=>({
    generated_at:new Date().toISOString(),
    lead:{available:true,state:'nothing-new',files:0,bytes:0,since:null},
    board:{rows:[],more:0,truncated:false,cargo:[],docked:[]},
    needs_you:{items:[]},
    away:{shares:{available:true,count:0},upload_requests:{available:true,count:0}},
    ...extra,
});
window.__homeAnswer=address=>{
    const path=String(address).split('?')[0];
    if(path.endsWith('/layout'))return {widgets:[],catalog:[],links:[]};
    if(path.endsWith('/summary'))return window.__homeSummary();
    if(path.endsWith('/calendar/mine'))return {mine:[],unassigned:[]};
    if(path.endsWith('/calendar/events'))return {events:[]};
    return null;
};`;

module.exports={homeRoot,homeApp,homeAnswersSource,homeScripts,homeStyles};
