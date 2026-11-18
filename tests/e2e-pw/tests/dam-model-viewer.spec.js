const {test,expect}=require('@playwright/test');
const fs=require('node:fs');
const path=require('node:path');
const http=require('node:http');
const {pathToFileURL}=require('node:url');
const zlib=require('node:zlib');
const root=path.resolve(__dirname,'../../..');
const build=process.env.DAM_MODEL_BUILD_DIR || path.join(root,'public/themes/dam/build');
let server,origin,threeMF,stl,coloredStl;

function png() {
    const crc=bytes=>{let c=0xffffffff;for(const byte of bytes){c^=byte;for(let i=0;i<8;i++)c=(c>>>1)^((c&1)?0xedb88320:0);}return (c^0xffffffff)>>>0;};
    const chunk=(name,data)=>{const type=Buffer.from(name),size=Buffer.alloc(4),checksum=Buffer.alloc(4);size.writeUInt32BE(data.length);checksum.writeUInt32BE(crc(Buffer.concat([type,data])));return Buffer.concat([size,type,data,checksum]);};
    const header=Buffer.alloc(13);header.writeUInt32BE(1,0);header.writeUInt32BE(1,4);header[8]=8;header[9]=6;
    return Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]),chunk('IHDR',header),chunk('IDAT',zlib.deflateSync(Buffer.from([0,255,0,0,255]))),chunk('IEND',Buffer.alloc(0))]);
}
function animatedGLB(duration=1) {
    const positions=new Float32Array([-1,-1,0,1,-1,0,0,1,0]);
    const normals=new Float32Array([0,0,1,0,0,1,0,0,1]);
    const uv=new Float32Array([0,0,1,0,.5,1]);
    const times=new Float32Array([0,duration]);const translations=new Float32Array([0,0,0,2,0,0]);
    const image=png();const arrays=[positions,normals,uv,times,translations];
    const chunks=arrays.map(a=>Buffer.from(a.buffer));chunks.push(image);
    let offset=0;const views=chunks.map(b=>{const v={buffer:0,byteOffset:offset,byteLength:b.length};offset+=Math.ceil(b.length/4)*4;return v;});
    const bin=Buffer.alloc(offset);chunks.forEach((b,i)=>b.copy(bin,views[i].byteOffset));
    const doc={asset:{version:'2.0'},scene:0,scenes:[{nodes:[0]}],nodes:[{name:'Animated red triangle',mesh:0}],
        meshes:[{primitives:[{attributes:{POSITION:0,NORMAL:1,TEXCOORD_0:2},material:0}]}],
        materials:[{pbrMetallicRoughness:{baseColorTexture:{index:0},metallicFactor:0,roughnessFactor:1},doubleSided:true}],
        textures:[{source:0}],images:[{bufferView:5,mimeType:'image/png'}],
        animations:[{name:'Slide',channels:[{sampler:0,target:{node:0,path:'translation'}}],samplers:[{input:3,output:4}]}],
        accessors:[{bufferView:0,componentType:5126,count:3,type:'VEC3',min:[-1,-1,0],max:[1,1,0]},
            {bufferView:1,componentType:5126,count:3,type:'VEC3'},{bufferView:2,componentType:5126,count:3,type:'VEC2'},
            {bufferView:3,componentType:5126,count:2,type:'SCALAR',min:[0],max:[duration]},{bufferView:4,componentType:5126,count:2,type:'VEC3'}],bufferViews:views,buffers:[{byteLength:bin.length}]};
    const json=Buffer.from(JSON.stringify(doc));const padded=Buffer.alloc(Math.ceil(json.length/4)*4,32);json.copy(padded);
    const header=Buffer.alloc(20);header.write('glTF');header.writeUInt32LE(2,4);header.writeUInt32LE(28+padded.length+bin.length,8);header.writeUInt32LE(padded.length,12);header.writeUInt32LE(0x4e4f534a,16);
    const bh=Buffer.alloc(8);bh.writeUInt32LE(bin.length);bh.writeUInt32LE(0x004e4942,4);
    return Buffer.concat([header,padded,bh,bin]);
}
test.beforeAll(async()=>{
    const fixtures=await import(pathToFileURL(path.join(root,'packages/Mothership/DAM/tests/js/model-fixture.mjs')).href);
    threeMF=Buffer.from(fixtures.fixture());
    // Two facets stacked along Z with zero file normals: the Z-up remap and the recomputed shading are both observable.
    stl=Buffer.from(fixtures.stlFixture({triangles:[[[0,0,0],[2,0,0],[0,2,0]],[[0,0,3],[2,0,3],[0,2,3]]]}));
    // Same geometry with the non-standard binary colour extension (facets painted pure red).
    coloredStl=Buffer.from(fixtures.stlFixture({triangles:[[[0,0,0],[2,0,0],[0,2,0]],[[0,0,3],[2,0,3],[0,2,3]]],color:[31,0,0]}));
    const manifest=JSON.parse(fs.readFileSync(path.join(build,'manifest.json')));
    const entry=manifest['src/Resources/assets/js/app.js'].file;
    server=http.createServer((req,res)=>{
        const pathname=new URL(req.url,'http://localhost').pathname;
        if(pathname==='/') {res.setHeader('Content-Type','text/html');res.end(`<style>html,body{margin:0;height:100%}#host{height:100%;width:100%}</style><div id="host"></div><script type="module" src="/bundle/${entry}"></script>`);return;}
        if(pathname==='/fixture.3mf') {res.end(threeMF);return;}
        if(pathname==='/fixture.glb') {res.end(animatedGLB());return;}
        if(pathname==='/fixture-long.glb') {res.end(animatedGLB(12));return;}
        if(pathname==='/fixture.stl') {res.end(stl);return;}
        if(pathname==='/fixture-colored.stl') {res.end(coloredStl);return;}
        if(pathname==='/bad.stl') {res.end('solid broken\n facet normal 0 0 0\n outer loop\n vertex 0 0 0\n endloop\n endfacet\nendsolid');return;}
        if(pathname==='/corrected.glb' && process.env.CORRECTED_GLB) {res.end(fs.readFileSync(process.env.CORRECTED_GLB));return;}
        if(pathname==='/bad.glb') {res.end('broken');return;}
        if(pathname==='/slow.glb') {setTimeout(()=>res.end(animatedGLB()),300);return;}
        let file;
        if(pathname.startsWith('/bundle/')) file=path.join(build,pathname.slice(8));
        if(pathname.startsWith('/themes/dam/build/')) file=path.join(build,pathname.slice('/themes/dam/build/'.length));
        if(/^\/real\/\d+\.(3mf|glb)$/.test(pathname) && process.env.MODEL_SAMPLE_DIR) file=path.join(process.env.MODEL_SAMPLE_DIR,path.basename(pathname));
        if(!file || !fs.existsSync(file)){res.statusCode=404;res.end();return;}
        res.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.wasm':'application/wasm'})[path.extname(file)] || 'application/octet-stream');fs.createReadStream(file).pipe(res);
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;
});
test.afterAll(async()=>{if(server?.listening)await new Promise(resolve=>server.close(resolve));});
async function mount(page,url,extension) {
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    await page.evaluate(async({url,extension})=>{const {ModelViewer}=await window.loadDamModelViewer();window.viewer=new ModelViewer({container:document.querySelector('#host'),url,extension});await window.viewer.ready;},{url,extension});
    await expect(page.locator('[data-dam-model-viewer]')).toHaveAttribute('data-dam-model-viewer','ready');
}
test('primary view converts the chosen camera angle into a persistent model orientation',async({page})=>{
    await mount(page,'/fixture.glb','glb');
    await page.locator('[data-dam-model-play]').click();
    const result=await page.evaluate(()=>{
        const v=window.viewer;
        if(typeof v.primaryViewPayload!=='function') return null;
        v.camera.position.set(4,0,0);v.controls.target.set(0,0,0);v.controls.update();
        return v.primaryViewPayload();
    });
    expect(result).not.toBeNull();
    expect(result.rotation[1]).toBeCloseTo(-Math.SQRT1_2,5);
    expect(result.rotation[3]).toBeCloseTo(Math.SQRT1_2,5);
    expect(result.view.distance).toBeCloseTo(4,5);
});
test('editable primary view saves, returns to saved framing and restores original',async({page})=>{
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    await page.evaluate(async()=>{
        const {ModelViewer}=await window.loadDamModelViewer();
        window.savedPayload=null;window.restored=false;
        window.viewer=new ModelViewer({container:document.querySelector('#host'),url:'/fixture.glb',extension:'glb',
            primaryView:{can_edit:true,view:{distance:8,aspect:1.5}},
            onSavePrimary:async payload=>{window.savedPayload=payload;},
            onRestorePrimary:async()=>{window.restored=true;}});
        await window.viewer.ready;
    });
    await page.getByRole('button',{name:'Set primary view',exact:true}).click();
    await page.getByRole('button',{name:'Roll right 90°',exact:true}).click();
    await page.getByRole('button',{name:'Save primary view',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.savedPayload)).not.toBeNull();
    expect(await page.evaluate(()=>window.savedPayload.rotation.some(n=>Math.abs(n)>.01))).toBe(true);
    await page.getByRole('button',{name:'Set primary view',exact:true}).click();
    await page.getByRole('button',{name:'Restore original',exact:true}).click();
    expect(await page.evaluate(()=>window.restored)).toBe(true);
});
test('saved framing adapts to a narrower preview and read-only viewers offer no save',async({page})=>{
    await page.setViewportSize({width:400,height:1000});
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    await page.evaluate(async()=>{
        const {ModelViewer}=await window.loadDamModelViewer();
        window.viewer=new ModelViewer({container:document.querySelector('#host'),url:'/fixture.glb',extension:'glb',primaryView:{can_edit:false,view:{distance:8,aspect:1.5}}});
        await window.viewer.ready;
    });
    const distance=await page.evaluate(()=>window.viewer.camera.position.distanceTo(window.viewer.controls.target));
    expect(distance).toBeGreaterThan(8);
    await expect(page.getByRole('button',{name:'Set primary view',exact:true})).toHaveCount(0);
});
test('fit model preserves the front chosen in the primary-view editor',async({page})=>{
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    await page.evaluate(async()=>{
        const {ModelViewer}=await window.loadDamModelViewer();
        window.viewer=new ModelViewer({container:document.querySelector('#host'),url:'/fixture.glb',extension:'glb',primaryView:{can_edit:true,view:null},onSavePrimary:async()=>{}});
        await window.viewer.ready;
        window.viewer.camera.position.set(20,0,0);window.viewer.controls.target.set(0,0,0);window.viewer.controls.update();
    });
    await page.getByRole('button',{name:'Set primary view',exact:true}).click();
    await page.getByRole('button',{name:'Fit model',exact:true}).click();
    const direction=await page.evaluate(()=>window.viewer.camera.position.clone().sub(window.viewer.controls.target).normalize().toArray());
    expect(direction[0]).toBeCloseTo(1,5);expect(direction[1]).toBeCloseTo(0,5);expect(direction[2]).toBeCloseTo(0,5);
});
test('cancel restores the model and a failed save keeps edits available for retry',async({page})=>{
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    await page.evaluate(async()=>{
        const {ModelViewer}=await window.loadDamModelViewer();
        window.viewer=new ModelViewer({container:document.querySelector('#host'),url:'/fixture.glb',extension:'glb',primaryView:{can_edit:true,view:null},
            onSavePrimary:async()=>{throw new Error('A newer model exists. Reopen the preview before saving.');}});
        await window.viewer.ready;
    });
    const before=await page.evaluate(()=>window.viewer.orientationGroup.quaternion.toArray());
    await page.getByRole('button',{name:'Set primary view',exact:true}).click();
    await page.getByRole('button',{name:'Roll right 90°',exact:true}).click();
    await page.getByRole('button',{name:'Cancel',exact:true}).click();
    expect(await page.evaluate(()=>window.viewer.orientationGroup.quaternion.toArray())).toEqual(before);
    await page.getByRole('button',{name:'Set primary view',exact:true}).click();
    await page.getByRole('button',{name:'Roll right 90°',exact:true}).click();
    await page.getByRole('button',{name:'Save primary view',exact:true}).click();
    await expect(page.getByRole('status').last()).toContainText('A newer model exists');
    await expect(page.getByRole('button',{name:'Save primary view',exact:true})).toBeEnabled();
    expect(await page.evaluate(()=>window.viewer.orientationGroup.quaternion.toArray())).not.toEqual(before);
});
test('server-corrected GLB retains textures and animation with the new world orientation',async({page})=>{
    test.skip(!process.env.CORRECTED_GLB,'Set CORRECTED_GLB to the output of GlbPrimaryView acceptance');
    await mount(page,'/corrected.glb','glb');
    await page.locator('[data-dam-model-play]').click();
    await page.getByLabel('Animation time',{exact:true}).fill('0.5');
    const result=await page.evaluate(()=>{
        const v=window.viewer;let mesh;v.root.traverse(o=>{if(o.isMesh)mesh=o;});
        v.root.updateWorldMatrix(true,true);
        return {width:mesh.material.map.image.width,localX:mesh.position.x,worldX:mesh.matrixWorld.elements[12],worldZ:mesh.matrixWorld.elements[14],clip:v.clips[0].name};
    });
    expect(result.width).toBe(1);expect(result.clip).toBe('Slide');expect(result.localX).toBeCloseTo(1,4);
    expect(result.worldX).toBeCloseTo(-1,4);expect(result.worldZ).toBeCloseTo(0,4);
});
test('Bambu plates, paint, camera controls and clean disposal',async({page})=>{
    await mount(page,'/fixture.3mf','3mf');
    await expect(page.getByLabel('Plate',{exact:true})).toHaveValue('all');
    expect(await page.evaluate(()=>window.viewer.entries.filter(e=>e.object.visible).length)).toBe(2);
    await page.getByLabel('Plate',{exact:true}).selectOption('1');
    expect(await page.evaluate(()=>window.viewer.entries.filter(e=>e.object.visible).map(e=>e.key))).toEqual(['2:0']);
    await page.getByLabel('Plate',{exact:true}).selectOption('2');
    expect(await page.evaluate(()=>window.viewer.entries.filter(e=>e.object.visible).map(e=>e.key))).toEqual(['2:1']);
    await page.getByLabel('Plate',{exact:true}).selectOption('all');
    expect(await page.evaluate(()=>window.viewer.entries.filter(e=>e.object.visible).length)).toBe(2);
    await page.locator('[data-dam-model-reset]').click();
    await page.locator('summary').click();await page.locator('input[type=checkbox]').first().uncheck();
    expect(await page.evaluate(()=>window.viewer.entries[0].object.visible)).toBe(false);
    await page.evaluate(()=>window.viewer.dispose());
    await expect(page.locator('canvas')).toHaveCount(0);
    expect(await page.evaluate(()=>({closed:window.viewer.closed,frame:window.viewer.frame,renderer:window.viewer.renderer}))).toEqual({closed:true,frame:0,renderer:null});
});
test('GLB textures render and animation pauses, scrubs, changes speed and resumes',async({page})=>{
    await mount(page,'/fixture.glb','glb');
    expect(await page.evaluate(()=>window.viewer.root.children[0].material.map.image.width)).toBe(1);
    await page.locator('[data-dam-model-play]').click();
    expect(await page.evaluate(()=>window.viewer.playing)).toBe(false);
    await page.getByLabel('Animation time',{exact:true}).fill('0.5');
    expect(await page.evaluate(()=>window.viewer.root.children[0].position.x)).toBeCloseTo(1,4);
    await page.getByLabel('Playback speed').selectOption('2');
    expect(await page.evaluate(()=>window.viewer.mixer.timeScale)).toBe(2);
    const before=await page.locator('canvas').screenshot();
    await page.getByLabel('Animation time',{exact:true}).fill('0');
    const after=await page.locator('canvas').screenshot();expect(before.equals(after)).toBe(false);
    await page.locator('[data-dam-model-play]').click();
    await expect.poll(()=>page.evaluate(()=>window.viewer.action.time)).toBeGreaterThan(.05);
    await page.evaluate(()=>window.viewer.dispose());await expect(page.locator('canvas')).toHaveCount(0);
});
test('closing during a download leaves no stale renderer; malformed GLB is actionable',async({page})=>{
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    await page.evaluate(async()=>{const {ModelViewer}=await window.loadDamModelViewer();const host=document.querySelector('#host');window.viewer=new ModelViewer({container:host,url:'/slow.glb',extension:'glb'});window.viewer.dispose();await window.viewer.ready;});
    await expect(page.locator('canvas')).toHaveCount(0);
    await page.evaluate(async()=>{const {ModelViewer}=await window.loadDamModelViewer();window.viewer=new ModelViewer({container:document.querySelector('#host'),url:'/bad.glb',extension:'glb'});await window.viewer.ready;});
    await expect(page.locator('[data-dam-model-viewer]')).toHaveAttribute('data-dam-model-viewer','error');
    await expect(page.getByRole('status')).toContainText('download the original or retry');await expect(page.locator('canvas')).toHaveCount(0);
});
test('STL mounts as one neutral Y-up mesh with model controls and no plate or animation UI',async({page})=>{
    await mount(page,'/fixture.stl','stl');
    const result=await page.evaluate(()=>{
        const v=window.viewer;let mesh;v.root.traverse(o=>{if(o.isMesh)mesh=o;});
        v.root.updateWorldMatrix(true,true);
        return {entries:v.entries.length,triangles:mesh.geometry.attributes.position.count/3,color:mesh.material.color.getHexString(),
            normal:Array.from(mesh.geometry.attributes.normal.array.slice(0,3)),
            rotationX:v.root.rotation.x,
            targetLen:v.controls.target.length(),distance:v.camera.position.distanceTo(v.controls.target)};
    });
    expect(result.entries).toBe(1);expect(result.triangles).toBe(2);expect(result.color).toBe('b8bec9');
    expect(result.normal).toEqual([0,0,1]);expect(result.rotationX).toBeCloseTo(-Math.PI/2,6);
    expect(result.distance).toBeGreaterThan(1);
    await expect(page.locator('[data-dam-model-plate]')).toHaveCount(0);
    await expect(page.locator('[data-dam-model-animation]')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Set primary view',exact:true})).toHaveCount(0);
    await expect(page.locator('[data-dam-model-reset]')).toBeVisible();
    await page.locator('summary').click();await expect(page.locator('input[type=checkbox]')).toHaveCount(1);
    const shot=await page.locator('canvas').screenshot();
    await page.locator('input[type=checkbox]').first().uncheck();
    expect((await page.locator('canvas').screenshot()).equals(shot)).toBe(false);
    await page.evaluate(()=>window.viewer.dispose());
    await expect(page.locator('canvas')).toHaveCount(0);
});
test('a colour-extension STL renders in the same neutral grey as any other STL',async({page})=>{
    await mount(page,'/fixture-colored.stl','stl');
    const result=await page.evaluate(()=>{
        const v=window.viewer;let mesh;v.root.traverse(o=>{if(o.isMesh)mesh=o;});
        v.camera.position.set(1,1,6);v.controls.target.set(1,1.5,-1.5);v.controls.update();v.renderer.render(v.scene,v.camera);
        const gl=v.renderer.getContext();const w=gl.drawingBufferWidth,h=gl.drawingBufferHeight;
        const px=new Uint8Array(w*h*4);gl.readPixels(0,0,w,h,gl.RGBA,gl.UNSIGNED_BYTE,px);
        // Look for lit model pixels: anything clearly brighter than the #222833 stage.
        let n=0,maxSpread=0;
        for(let i=0;i<px.length;i+=4){const r=px[i],g=px[i+1],b=px[i+2];if(r+g+b>3*90){n++;maxSpread=Math.max(maxSpread,Math.max(r,g,b)-Math.min(r,g,b));}}
        return {hasColors:mesh.geometry.hasColors===true,vertexColors:mesh.material.vertexColors,color:mesh.material.color.getHexString(),litPixels:n,maxSpread};
    });
    expect(result.hasColors).toBe(true);
    expect(result.vertexColors).toBe(false);expect(result.color).toBe('b8bec9');
    expect(result.litPixels).toBeGreaterThan(100);
    // Neutral grey under white lights: channels stay within a narrow band; painted red would spread by >100.
    expect(result.maxSpread).toBeLessThan(40);
});
test('a malformed STL reports an actionable error instead of a blank stage',async({page})=>{
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    await page.evaluate(async()=>{const {ModelViewer}=await window.loadDamModelViewer();window.viewer=new ModelViewer({container:document.querySelector('#host'),url:'/bad.stl',extension:'stl'});await window.viewer.ready;});
    await expect(page.locator('[data-dam-model-viewer]')).toHaveAttribute('data-dam-model-viewer','error');
    await expect(page.getByRole('status')).toContainText('download the original or retry');await expect(page.locator('canvas')).toHaveCount(0);
});
test('initial portrait framing uses the real viewport aspect',async({page})=>{
    await page.setViewportSize({width:400,height:1000});await mount(page,'/fixture.3mf','3mf');
    const before=await page.evaluate(()=>window.viewer.camera.position.toArray());
    await page.locator('[data-dam-model-reset]').click();
    const after=await page.evaluate(()=>window.viewer.camera.position.toArray());
    for(let i=0;i<3;i++)expect(before[i]).toBeCloseTo(after[i],4);
});
test('a viewer mounted into a host that has no size yet frames itself once the host is laid out',async({page})=>{
    await page.setViewportSize({width:400,height:1000});
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    // Reference: the same model in the same portrait host, visible from the start.
    const reference=await page.evaluate(async()=>{
        const {ModelViewer}=await window.loadDamModelViewer();
        const viewer=new ModelViewer({container:document.querySelector('#host'),url:'/fixture.3mf',extension:'3mf'});
        await viewer.ready;const distance=viewer.camera.position.distanceTo(viewer.controls.target);viewer.dispose();return distance;
    });
    const hidden=await page.evaluate(async()=>{
        const {ModelViewer}=await window.loadDamModelViewer();
        const host=document.querySelector('#host');host.style.display='none';
        window.viewer=new ModelViewer({container:host,url:'/fixture.3mf',extension:'3mf'});
        await window.viewer.ready;
        const before=window.viewer.camera.position.distanceTo(window.viewer.controls.target);
        host.style.display='';
        await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
        return {before,after:window.viewer.camera.position.distanceTo(window.viewer.controls.target),aspect:window.viewer.camera.aspect};
    });
    expect(hidden.aspect).toBeCloseTo(400/(await page.evaluate(()=>document.querySelector('.dam-model-stage').getBoundingClientRect().height)),2);
    expect(hidden.after).toBeCloseTo(reference,4);
    expect(hidden.before).not.toBeCloseTo(reference,4);
});
test('the animation time label keeps one width as digits roll over, so playback never reflows the stage',async({page})=>{
    await mount(page,'/fixture-long.glb','glb');
    await page.locator('[data-dam-model-play]').click();
    const width=async value=>{await page.getByLabel('Animation time',{exact:true}).fill(value);return page.evaluate(()=>document.querySelector('.dam-model-time').getBoundingClientRect().width);};
    const stage=async()=>page.evaluate(()=>document.querySelector('.dam-model-stage').getBoundingClientRect().height);
    const narrow=await width('9.9');const stageNarrow=await stage();
    const wide=await width('10');
    expect(wide).toBeCloseTo(narrow,1);expect(await stage()).toBe(stageNarrow);
});
test('closing during compressed GLB decoding settles and releases decoder workers',async({page})=>{
    test.skip(!process.env.MODEL_SAMPLE_DIR || !fs.existsSync(path.join(process.env.MODEL_SAMPLE_DIR,'90001.glb')),'Optional official Draco acceptance sample');
    await page.goto(origin);await page.waitForFunction(()=>window.loadDamModelViewer);
    const result=await page.evaluate(async()=>{
        const {ModelViewer}=await window.loadDamModelViewer();const viewer=new ModelViewer({container:document.querySelector('#host'),url:'/real/90001.glb',extension:'glb'});
        while(!viewer.draco?.workerPool.length && viewer.container.dataset.damModelViewer==='loading')await new Promise(r=>setTimeout(r,1));
        viewer.dispose();const settled=await Promise.race([viewer.ready.then(()=>true),new Promise(r=>setTimeout(()=>r(false),5000))]);
        return {settled,workers:viewer.draco?.workerPool.length,canvases:document.querySelectorAll('canvas').length};
    });
    expect(result).toEqual({settled:true,workers:0,canvases:0});
});
for(const [id,ext] of [[471,'3mf'],[549,'3mf'],[552,'3mf'],[5447,'glb'],[5511,'glb']]) {
    test(`real library ${id}.${ext}`,async({page},testInfo)=>{
        test.skip(!process.env.MODEL_SAMPLE_DIR,'Set MODEL_SAMPLE_DIR to local copies of acceptance samples');
        await mount(page,`/real/${id}.${ext}`,ext);
        if(ext==='3mf')expect(await page.locator('[data-dam-model-plate] option').count()).toBeGreaterThan(4);
        else {await expect(page.locator('[data-dam-model-animation]')).toBeVisible();await expect.poll(()=>page.evaluate(()=>window.viewer.action.time)).toBeGreaterThan(.1);await page.locator('[data-dam-model-play]').click();}
        await page.screenshot({path:testInfo.outputPath(`${id}.png`)});
        await page.evaluate(()=>window.viewer.dispose());await expect(page.locator('canvas')).toHaveCount(0);
    });
}
