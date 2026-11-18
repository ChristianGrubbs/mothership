'use strict';
// The fixed Home page's root markup for browser tests. It mirrors
// packages/Mothership/DAM/src/Resources/views/home/index.blade.php: keep the two in step, so a test
// never carries its own copy of the page.

const attribute=value=>String(value).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');

const DEFAULT_STARTS=[
    {key:'upload',label:'Upload files',help:'Add files to a folder',start:'upload'},
    {key:'send',label:'Send files',help:'Straight to a person',start:'send',urlFallback:'/admin/transfers'},
    {key:'library',label:'Open the Library',help:'Browse every file',url:'/admin/dam'},
];

function startItem(action){
    const inner=`<span class="agh-start-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg></span><span class="agh-start-text"><strong>${attribute(action.label)}</strong><small>${attribute(action.help||'')}</small></span>`;
    if(action.url)return `<a class="agh-start-item" href="${attribute(action.url)}" data-start-key="${attribute(action.key)}">${inner}</a>`;
    return `<button type="button" class="agh-start-item" data-start-key="${attribute(action.key)}"${action.start?` data-start="${attribute(action.start)}"`:''}${action.urlFallback?` data-start-url="${attribute(action.urlFallback)}"`:''}${action.open?` data-start-open="${attribute(action.open)}"`:''}>${inner}</button>`;
}

/**
 * @param {object} [options]
 * @param {boolean} [options.superuser] full-access account: data-superuser="1" and the Studio admin section
 * @param {boolean} [options.transfers] Transfers is configured: the "Send files to a person" block
 * @param {boolean} [options.library] the account may search the Library: the hero search form
 * @param {Array<object>} [options.starts] hero "Start something" items
 * @param {string} [options.tag] root element name
 * @param {Object<string,string|null>} [options.attrs] extra or replaced root attributes; null drops one
 */
function homeRoot({superuser=false,transfers=false,library=true,starts=DEFAULT_STARTS,tag='div',attrs={}}={}){
    const rootAttributes={
        'data-base':'/admin/home','data-csrf':'fixture','data-user':'1','data-name':'Test',
        'data-library':'/admin/dam','data-upload':'/admin/dam/assets/upload',
        'data-directory-search':'/admin/dam/directory/search','data-directories':'/admin/dam/directory',
        'data-library-allowed':library?'1':'0','data-superuser':superuser?'1':'0',...attrs,
    };
    const attributeText=Object.entries(rootAttributes).filter(([,value])=>value!==null).map(([name,value])=>`${name}="${attribute(value)}"`).join(' ');
    const search=library?'<form class="agh-search" action="/admin/dam" method="GET" accept-charset="UTF-8" role="search" aria-label="Search all files" data-no-ajax-nav><input type="hidden" name="search_submit" value="1"><label for="agh-home-search">Search all files</label><div class="agh-search-row"><input id="agh-home-search" name="search" type="search" maxlength="255" autocomplete="off" placeholder="Type a name, a tag, or what is in the picture" data-home-search><button type="submit" class="agh-button agh-primary">Search</button></div></form>':'';
    const send=transfers?'<section class="agh-section agh-feature" aria-labelledby="agh-send-title" data-block="send" data-id="transfers"><div class="agh-section-head"><h2 id="agh-send-title">Send files to a person</h2><a href="/admin/transfers" data-send-open>Open Transfers <span aria-hidden="true">›</span></a></div><p class="agh-section-note">Files go from your browser to theirs, directly where the network allows and through The Mothership where it does not. Nothing is stored in between, so both of you need The Mothership open.</p><div class="agh-send" data-send><p class="agh-caption" role="status">Looking for people who are online…</p></div></section>':'';
    const admin=superuser?'<section class="agh-section agh-admin" aria-labelledby="agh-status-title" data-section="status" hidden><div class="agh-section-head"><h2 id="agh-status-title">Studio admin</h2><span class="agh-admin-note">Only administrators see this part of Home.</span></div><div class="agh-slot agh-around" data-slot="status"></div></section>':'';
    return `<${tag} data-mothership-home ${attributeText}>`
        +`<header class="agh-hero"><p class="agh-hero-date" data-today></p><h1 data-greeting>Welcome, Test</h1><p class="agh-hero-brief" data-brief>Find a file, start a task, or see what is coming up.</p>${search}<nav class="agh-start" aria-label="Start something">${starts.map(startItem).join('')}</nav></header>`
        +'<div role="status" aria-live="polite" class="agh-status" data-status>Loading your Home…</div>'
        +'<div class="agh-sections" data-grid>'
        +'<div class="agh-day"><section class="agh-section" aria-labelledby="agh-day-title" data-section="day" hidden><h2 id="agh-day-title">Your day</h2><div class="agh-agenda" data-day data-id="calendar"></div></section>'
        +`<div class="agh-side"><section class="agh-section agh-apps" aria-labelledby="agh-apps-title" data-section="apps"><h2 id="agh-apps-title">Apps</h2><div class="agh-section-head agh-apps-head"><h3 id="agh-tools-title">Studio tools</h3><a href="https://icons.example.org/" target="_blank" rel="noopener noreferrer">Icon Library <span aria-hidden="true">↗</span></a></div><ul class="agh-app-grid" aria-labelledby="agh-tools-title"><li data-tools-end><button type="button" class="agh-app" data-id="phone-app" data-phone-open title="Put The Mothership on your iPhone or iPad"><span class="agh-app-icon agh-app-phone" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 2.5h8a1.5 1.5 0 0 1 1.5 1.5v16a1.5 1.5 0 0 1-1.5 1.5H8A1.5 1.5 0 0 1 6.500 20V4A1.5 1.5 0 0 1 8 2.500z M10.500 5h3 M12 12v5 M9.500 14.500L12 17l2.500-2.500"/></svg></span><strong>iPhone app</strong><small>Get The Mothership on your phone</small></button></li></ul></section>${send}</div></div>`
        +'<section class="agh-section" aria-labelledby="agh-recent-title" data-section="recent" hidden><div class="agh-section-head"><h2 id="agh-recent-title">Recent uploads</h2><a href="/admin/dam">See all files <span aria-hidden="true">›</span></a></div><div class="agh-work" data-recent data-id="recent"></div><div class="agh-starred" data-starred data-id="favorites"></div></section>'
        +admin
        +'<section class="agh-section" aria-labelledby="agh-links-title" data-section="links" hidden><h2 id="agh-links-title">Your saved links</h2><div class="agh-saved-links" data-saved-links></div></section>'
        +'</div>'
        +'<dialog class="agh-settings agh-calendar-dialog" data-calendar-dialog aria-labelledby="agh-calendar-title"><div class="agh-dialog-head"><h2 id="agh-calendar-title">Calendar</h2><button type="button" class="agh-button" data-calendar-close aria-label="Close calendar">✕</button></div><div class="agh-slot" data-slot="coming"></div></dialog>'
        +'<dialog class="agh-settings agh-upload" data-upload-dialog aria-labelledby="agh-upload-title"></dialog>'
        +'<dialog class="agh-settings agh-phone" data-phone-dialog aria-labelledby="agh-phone-title"><div class="agh-dialog-head"><h2 id="agh-phone-title">The Mothership on your iPhone</h2><button type="button" class="agh-button" data-phone-close aria-label="Close">✕</button></div><div class="agh-phone-body"><div><p class="agh-section-note">There is nothing to download from the App Store. Add The Mothership to your Home Screen and it opens like any other app.</p><ol class="agh-phone-steps"><li>On your iPhone or iPad, open <b>mothership.test</b> in Safari and sign in.</li><li>Tap <b>Share</b> at the bottom of the screen.</li><li>Tap <b>Add to Home Screen</b>, then <b>Add</b>.</li></ol><p class="agh-section-note agh-phone-foot">On an Android phone, open the same address in Chrome and choose <b>Install app</b> from the menu.</p></div><div class="agh-phone-art" aria-hidden="true"><span class="agh-phone-app"></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div></dialog>'
        +`</${tag}>`;
}

// The root inside the admin shell's #app, which Home marks inert while a tool is open.
const homeApp=options=>`<div id="app">${homeRoot(options)}</div>`;

// Page script: window.__homeAnswer(address) gives an empty, well-formed body for every endpoint the
// fixed page reads on its own, or null for anything else. A test's fetch stub answers what it cares
// about and falls back to this, so no part of the page meets a body it cannot read.
const homeAnswersSource=`window.__homeAnswer=address=>{
    const path=String(address).split('?')[0];
    if(path.endsWith('/layout'))return {widgets:[],catalog:[],links:[]};
    if(path.endsWith('/calendar/mine'))return {mine:[],unassigned:[]};
    if(path.endsWith('/calendar/events'))return {events:[]};
    if(path.endsWith('/data/recent'))return {assets:[]};
    if(path.endsWith('/favorites'))return {assets:[]};
    return null;
};`;

module.exports={homeRoot,homeApp,homeAnswersSource};
