const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

// Quick-fixes packet (2026-09-22): the 3D Preview card, the info popover, followable
// relationships, forced thumbnail re-capture, and the Studio load card's layout. (The Quick links
// card it also covered went away with the fixed Home page.)
const root=path.resolve(__dirname,'../../..');
const base='packages/Mothership/DAM/src/';
const read=file=>fs.readFileSync(path.join(root,base+file),'utf8');
const card=read('Resources/views/asset/preview-modal/card.blade.php');
const previewModal=read('Resources/views/asset/preview-modal.blade.php');
const relationships=read('Resources/views/asset/relationships/index.blade.php');
const viewerScript=read('Resources/views/asset/preview-modal/model/viewer-script.blade.php');
const viewer=read('Resources/assets/js/model/viewer.mjs');
const css=read('Resources/assets/css/home.css');
const quickViewSource=read('Resources/assets/js/home-system-quickview.js');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;

// Extracts one method or computed getter's body out of the preview-modal component so its
// real logic runs, the same vm-sandbox way dam-preview-navigation.test.cjs does.
function componentFunction(name){
    const start=previewModal.indexOf(`            ${name}(`);
    assert.notEqual(start,-1,`${name} not found in preview-modal.blade.php`);
    const open=previewModal.indexOf('{',start);
    let depth=0,end=open;
    for(;end<previewModal.length;end++){const c=previewModal[end];if(c==='{')depth++;else if(c==='}'&&--depth===0)break;}
    const params=previewModal.slice(previewModal.indexOf('(',start)+1,previewModal.indexOf(')',start));
    // @json(route('x', ['id' => '__ASSET__'])) nests one level of parentheses.
    const body=previewModal.slice(open+1,end).replace(/@(?:json|js)\((?:[^()]|\([^()]*\))*\)/g,"'/thumb'");
    return vm.runInNewContext(`(function(${params}){${body}})`,{window:{innerWidth:1200},Number,Math,Date,encodeURIComponent});
}

test('3D card: the server-sent model thumbnail sits under the CTA, swapped on capture, and the click still opens the viewer',()=>{
    const branch=card.slice(card.indexOf("['3mf', 'glb', 'stl']"),card.indexOf('</template>',card.indexOf("['3mf', 'glb', 'stl']")));
    assert.match(branch,/data-dam-model-open/);
    assert.match(branch,/@click="openPreview"/,'the CTA click behaviour is preserved');
    assert.match(branch,/<img[\s\S]*v-if="previewData\.modelThumbnailUrl"[\s\S]*:key="previewData\.modelThumbnailUrl"[\s\S]*:src="previewData\.modelThumbnailUrl"[\s\S]*data-dam-model-thumbnail/);
    assert.match(branch,/v-on:error="\$event\.target\.hidden = true"/,'a failed load hides the image rather than showing a broken icon');
    // ModelThumbnailService::cardUrl builds the URL: a browser-built ?path= had no revision on
    // first load and was decoded twice by FileController::thumbnail().
    assert.doesNotMatch(previewModal,/modelThumbnailVersion|encodeURIComponent\(this\.previewData\.path\)/);
    assert.match(previewModal,/\$emitter\.on\('dam:thumbnail-updated', this\._onThumbnailUpdated\)/);
    assert.match(previewModal,/\$emitter\.off\('dam:thumbnail-updated', this\._onThumbnailUpdated\)/);
    const updated=componentFunction('onModelThumbnailUpdated');
    const card3d={previewData:{id:4,modelThumbnailUrl:null}};
    updated.call(card3d,{id:5,url:'/other'});
    updated.call(card3d,{id:4});
    updated.call(card3d,undefined);
    assert.equal(card3d.previewData.modelThumbnailUrl,null,'another asset, or no URL, leaves the card alone');
    updated.call(card3d,{id:'4',url:'/thumb?path=a&v=9f'});
    assert.equal(card3d.previewData.modelThumbnailUrl,'/thumb?path=a&v=9f');
    // Prev/next navigation takes the URL from show(); a model with no capture keeps the text-only card.
    const changed=componentFunction('onDamAssetChanged');
    const target={previewData:{id:4,typeColor:'c',modelThumbnailUrl:'/thumb?path=a&v=9f'}};
    const nav=new Proxy(target,{get:(t,k)=>k in t?t[k]:()=>{}});
    changed.call(nav,{asset:{id:5,extension:'glb'},modelThumbnailUrl:'/thumb?path=b&v=5-abc'});
    assert.equal(target.previewData.modelThumbnailUrl,'/thumb?path=b&v=5-abc');
    changed.call(nav,{asset:{id:6,extension:'stl'}});
    assert.equal(target.previewData.modelThumbnailUrl,null);
});

test('PDF card: shows the server-sent thumbnail URL, also after prev/next navigation',()=>{
    const branch=card.slice(card.indexOf("=== 'pdf'"),card.indexOf('</template>',card.indexOf("=== 'pdf'")));
    assert.match(branch,/<img v-if="previewData\.pdfThumbnailUrl" :src="previewData\.pdfThumbnailUrl"/);
    // AssetHelper::pdfThumbnailUrl builds the URL: a browser-built ?path= was decoded twice by FileController::thumbnail().
    assert.doesNotMatch(branch,/encodeURIComponent|file\.thumbnail/);
    const changed=componentFunction('onDamAssetChanged');
    const target={previewData:{id:4,typeColor:'c',pdfThumbnailUrl:'/thumb?path=a'}};
    const nav=new Proxy(target,{get:(t,k)=>k in t?t[k]:()=>{}});
    changed.call(nav,{asset:{id:5,extension:'pdf'},pdfThumbnailUrl:'/thumb?path=b%252B'});
    assert.equal(target.previewData.pdfThumbnailUrl,'/thumb?path=b%252B');
    changed.call(nav,{asset:{id:6,extension:'png'}});
    assert.equal(target.previewData.pdfThumbnailUrl,null);
});

test('info popover: teleported out of the clipping column and never rendered empty',()=>{
    const popover=card.slice(card.indexOf('<teleport to="body">'),card.indexOf('</teleport>'));
    assert.ok(popover.length>0,'popover is teleported to body');
    assert.match(popover,/v-if="infoPopoverVisible"/);
    assert.match(popover,/:style="infoPopoverStyle"/);
    assert.doesNotMatch(card,/v-show="infoHover"/,'the old always-present box is gone');
    const visible=componentFunction('infoPopoverVisible');
    assert.equal(visible.call({infoHover:true,infoPopoverStyle:{top:'1px'},previewData:{display_name:'',file_name:''}}),false,'no name, no box');
    assert.equal(visible.call({infoHover:true,infoPopoverStyle:null,previewData:{file_name:'x.jpg'}}),false,'no anchor rect, no box');
    assert.equal(visible.call({infoHover:false,infoPopoverStyle:{top:'1px'},previewData:{file_name:'x.jpg'}}),false);
    assert.equal(visible.call({infoHover:true,infoPopoverStyle:{top:'1px'},previewData:{file_name:'x.jpg'}}),true);
    const show=componentFunction('showInfoPopover');
    const state={infoHover:false,infoPopoverStyle:null};
    show.call(state,{currentTarget:{getBoundingClientRect:()=>({right:1000,bottom:50})}});
    assert.equal(state.infoHover,true);
    assert.equal(state.infoPopoverStyle.position,'fixed');
    assert.equal(state.infoPopoverStyle.top,'54px');
    assert.equal(state.infoPopoverStyle.left,'776px','right-aligned to the button');
    show.call(state,{currentTarget:{getBoundingClientRect:()=>({right:1195,bottom:50})}});
    assert.equal(state.infoPopoverStyle.left,'968px','kept inside the viewport');
});

test('preview modal ignores a position-only dam-asset-changed payload instead of blanking previewData',()=>{
    const handler=componentFunction('onDamAssetChanged');
    const state={previewData:{id:1,display_name:'Kept',typeColor:'c'},closePreview(){throw new Error('must not reset the card for a counter-only update');}};
    handler.call(state,{assetPosition:2,assetTotal:9});
    assert.equal(state.previewData.display_name,'Kept');
    handler.call(state,undefined);
    assert.equal(state.previewData.display_name,'Kept');
});

test('relationship rows link to the related asset and keep delete separate',()=>{
    assert.match(relationships,/<a :href="relationship\.asset\.url" class="block" data-dam-relationship-link>\s*<img/);
    assert.match(relationships,/<a\s+:href="relationship\.asset\.url"[\s\S]*?data-dam-relationship-link\s*>\s*@\{\{ relationship\.asset\.display_name/);
    assert.match(relationships,/data-dam-relationship-open/);
    assert.match(relationships,/@click="removeRelationship\(relationship\.relationship_id\)"/);
    assert.match(relationships,/section\.direction === 'outgoing' \? '→' : '←'/,'direction is visible per row');
    const service=read('Services/AssetRelationshipService.php');
    assert.match(service,/'url'\s*=>\s*\$url,/);
    assert.match(service,/route\('admin\.dam\.assets\.edit', \$otherAsset->id\)/);
});

test('viewer: flip controls, build plates out of the fit box, and a forced re-capture after a primary-view save/restore',()=>{
    assert.match(viewer,/\['Flip left\/right 180°','y','horizontal'\],\['Flip up\/down 180°','x','vertical'\]/);
    assert.match(viewer,/rotatePrimary\(\{axis,angle:Math\.PI\}\)/);
    assert.match(viewer,/buildBuildPlates\(\{root:this\.root,entries:this\.entries,plates:this\.plates,printableArea:data\.printableArea\}\)/);
    assert.match(viewer,/bed\.object\.visible=!plate \|\| bed\.id===plate\.id/,'plates follow the plate selector');
    assert.match(viewer,/object\.isMesh && !object\.userData\.damBuildPlate/,'fit() ignores the bed');
    assert.equal((viewerScript.match(/this\.recaptureThumbnail=true;/g)||[]).length,2,'both save and restore request a re-capture');
    assert.match(viewerScript,/if\(data\.has_thumbnail && !replace\)return;/);
    assert.match(viewerScript,/if\(replace\)this\.recaptureThumbnail=false;/,'the re-capture request survives until the server answers');
    assert.match(viewerScript,/if\(replace\)form\.append\('replace','1'\)/);
    assert.match(read('Http/Controllers/ModelThumbnailController.php'),/'replace'\s*=> 'sometimes\|boolean'/);
});

async function withBrowser(run){
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{await run(browser);}finally{await browser.close();}
}

browserTest('Studio & people: GPU label never overlaps the Memory value, even in a narrow card',async()=>withBrowser(async browser=>{
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    await page.setContent('<div style="width:300px;height:140px;display:flex"><article class="agh-card" data-id="system-quickview" style="display:flex;flex-direction:column;width:300px;height:140px"><div class="agh-card-body"></div></article></div>');
    await page.addStyleTag({content:css});
    await page.addScriptTag({content:quickViewSource});
    await page.evaluate(()=>{
        const request=()=>new Promise(()=>{});
        const view=window.MothershipSystemQuickView.create({root:document,request,intervalMs:10000,now:()=>Date.now(),reducedMotion:true});
        view.mount(document.querySelector('[data-id="system-quickview"]'));
        document.querySelectorAll('[data-qv-value]').forEach(el=>{el.textContent='100%';});
    });
    const boxes=await page.evaluate(()=>[...document.querySelectorAll('.agh-qv-label')].map(label=>{const s=label.querySelector('span').getBoundingClientRect(),v=label.querySelector('strong').getBoundingClientRect();return {label:s,value:v};}));
    assert.equal(boxes.length,3);
    for(const {label,value} of boxes) assert.ok(label.right<=value.left+.5,`label (${label.right}) runs into its value (${value.left})`);
    for(let i=1;i<boxes.length;i++){
        const prev=boxes[i-1].value,next=boxes[i].label;
        // Side by side (next meter starts further right): its label must begin after the previous value ends.
        if(next.left>boxes[i-1].label.left) assert.ok(prev.right<=next.left+.5,`a value (${prev.right}) runs into the next meter's label (${next.left})`);
    }
}));
