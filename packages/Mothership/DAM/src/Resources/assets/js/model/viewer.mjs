import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';
import { KTX2Loader } from 'three/addons/loaders/KTX2Loader.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import dracoWasm from 'three/examples/jsm/libs/draco/gltf/draco_decoder.wasm?url';
import dracoWrapper from 'three/examples/jsm/libs/draco/gltf/draco_wasm_wrapper.js?url';
import basisWasm from 'three/examples/jsm/libs/basis/basis_transcoder.wasm?url';
import basisWrapper from 'three/examples/jsm/libs/basis/basis_transcoder.js?url';
import { buildThreeMFScene, buildSTLScene, buildBuildPlates, disposeModel } from './model-scene.mjs';
import { MAX_MODEL_BYTES as MAX_BYTES, TOO_LARGE_MESSAGE } from './limits.mjs';
import { canRestorePrimary } from './primary-view.mjs';
import { resolveExpectedBytes, formatDownloadProgress } from './load-policy.mjs';
import './viewer.css';

function element(tag, text, parent) {
    const node=document.createElement(tag);
    if (text) node.textContent=text;
    parent?.append(node);
    return node;
}
function button(text, parent, action) {
    const node=element('button',text,parent); node.type='button'; node.onclick=action; return node;
}

export class ModelViewer {
    constructor({container,url,extension,primaryView=null,onSavePrimary=null,onRestorePrimary=null,expectedBytes=0,onFailure=null,labels={}}) {
        this.container=container; this.url=url; this.extension=extension.toLowerCase();
        this.expectedBytes=expectedBytes;this.onFailure=onFailure;this.labels=labels;
        this.primaryView=primaryView;this.onSavePrimary=onSavePrimary;this.onRestorePrimary=onRestorePrimary;
        this.closed=false; this.frame=0; this.playing=false; this.entries=[]; this.listeners=[]; this.loadError=null;
        this.runId=crypto.randomUUID(); this.abort=new AbortController(); this.lastTime=0;
        container.classList.add('dam-model-viewer'); container.dataset.damModelViewer='loading';
        this.status=element('p','Loading 3D model…',container); this.status.setAttribute('role','status');
        // A 230 MB GLB takes long enough to download and parse that a bare status line reads as a hang,
        // so the load shows a bar: determinate while bytes arrive (when the size is known), then
        // indeterminate with an elapsed-seconds counter while the model is parsed.
        this.progress=element('progress','',container); this.progress.className='dam-model-progress'; this.progress.hidden=true;
        this.toolbar=element('div','',container); this.toolbar.className='dam-model-toolbar'; this.toolbar.hidden=true;
        this.stage=element('div','',container); this.stage.className='dam-model-stage';
        this.log('load_start',{format:this.extension});
        this.ready=this.load().catch(error=>{
            // Retained so a caller that only awaits `ready` (thumbnail-capture.mjs)
            // can tell a size refusal from a malformed file, instead of reading
            // the human-facing status text back out of the DOM.
            this.loadError=error;
            if(this.closed) return;
            this.stopRuntime();
            this.status.textContent=`Unable to preview this model: ${error.message}. You can download the original or retry.`;
            this.status.hidden=false; container.dataset.damModelViewer='error';
            this.log('load_error',{name:error.name});
            this.onFailure?.();
        });
    }
    log(event,ctx={}) {
        if(import.meta.env?.VITE_DAM_MODEL_DEBUG!=='true') return;
        try {
            const key='mothership.model-preview.log';
            const line=JSON.stringify({ts:new Date().toISOString(),run_id:this.runId,level:event.endsWith('error')?'error':'info',event,loc:'dam/model/viewer',ctx});
            sessionStorage.setItem(key,[...(sessionStorage.getItem(key)||'').split('\n').filter(Boolean).slice(-99),line].join('\n'));
        } catch { /* Optional diagnostics never block a preview. */ }
    }
    listen(target,event,fn) { target.addEventListener(event,fn); this.listeners.push(()=>target.removeEventListener(event,fn)); }
    async readSource() {
        const response=await fetch(this.url,{signal:this.abort.signal,credentials:'same-origin'});
        if(!response.ok) throw new Error(`Download returned HTTP ${response.status}`);
        if(Number(response.headers.get('Content-Length'))>MAX_BYTES) throw new Error(TOO_LARGE_MESSAGE);
        if(!response.body) {
            const buffer=await response.arrayBuffer();
            if(buffer.byteLength>MAX_BYTES) throw new Error(TOO_LARGE_MESSAGE);
            return buffer;
        }
        const reader=response.body.getReader(), chunks=[]; let total=0;
        // Content-Length describes wire bytes when content-encoding is present.
        const expected=response.headers.get('Content-Encoding') ? 0 : Number(response.headers.get('Content-Length'));
        // Progress falls back to the size the page already knows when the response carries no usable length.
        const progressTotal=resolveExpectedBytes({contentLength:expected,fallbackBytes:this.expectedBytes});
        const direct=Number.isSafeInteger(expected) && expected>0 ? new Uint8Array(expected) : null;
        for(;;) {
            const {done,value}=await reader.read(); if(done) break;
            total+=value.length;
            if(total>MAX_BYTES) {await reader.cancel(); throw new Error(TOO_LARGE_MESSAGE);}
            if(direct) {
                if(total>direct.length) {await reader.cancel();throw new Error('Download length changed; retry the preview');}
                direct.set(value,total-value.length);
            } else chunks.push(value);
            if(!this.closed) this.showDownloadProgress(total,progressTotal);
        }
        if(direct) {
            if(total!==direct.length)throw new Error('Incomplete model download; retry the preview');
            return direct.buffer;
        }
        const bytes=new Uint8Array(total); let offset=0;
        for(const chunk of chunks) {bytes.set(chunk,offset);offset+=chunk.length;}
        return bytes.buffer;
    }
    showDownloadProgress(loaded,expected) {
        const {text,percent}=formatDownloadProgress({loaded,expected});
        this.progress.hidden=false;
        if(percent!==null) {this.progress.max=expected; this.progress.value=loaded;}
        else this.progress.removeAttribute('value');
        this.status.textContent=`Downloading 3D model… ${text}`;
    }
    startPreparing(byteLength) {
        const started=performance.now(), size=`${(byteLength/1048576).toFixed(1)} MB`;
        this.progress.hidden=false; this.progress.removeAttribute('value');
        const tick=()=>{
            if(this.closed) return;
            const seconds=Math.floor((performance.now()-started)/1000);
            this.status.textContent=`Preparing model (${size} downloaded)…${seconds>=2 ? ` ${seconds}s` : ''}`;
        };
        tick(); this.prepareTimer=setInterval(tick,1000);
    }
    stopPreparing() { clearInterval(this.prepareTimer); this.prepareTimer=0; this.progress.hidden=true; }
    async load() {
        const buffer=await this.readSource(); if(this.closed) return;
        this.startPreparing(buffer.byteLength);
        try { await this.buildScene(buffer); } finally { this.stopPreparing(); }
    }
    async buildScene(buffer) {
        this.renderer=new THREE.WebGLRenderer({antialias:true,alpha:false});
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1,2));
        this.renderer.outputColorSpace=THREE.SRGBColorSpace;
        this.renderer.toneMapping=THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure=1;
        this.renderer.domElement.setAttribute('aria-label','Interactive 3D model: drag to orbit, right-drag to pan, scroll to zoom');
        this.stage.append(this.renderer.domElement);
        this.scene=new THREE.Scene(); this.scene.background=new THREE.Color('#222833');
        this.camera=new THREE.PerspectiveCamera(40,1,.01,10000);
        this.controls=new OrbitControls(this.camera,this.renderer.domElement);
        this.controls.enableDamping=false;
        this.controls.addEventListener('change',()=>this.requestRender());
        const pmrem=new THREE.PMREMGenerator(this.renderer), room=new RoomEnvironment();
        this.environment=pmrem.fromScene(room,.04); this.scene.environment=this.environment.texture;
        room.dispose(); pmrem.dispose();
        this.scene.add(new THREE.HemisphereLight(0xffffff,0x465167,1.3));
        const light=new THREE.DirectionalLight(0xffffff,2);light.position.set(3,5,4);this.scene.add(light);
        if(this.extension==='3mf') await this.loadThreeMF(buffer); else if(this.extension==='stl') this.loadSTL(buffer); else await this.loadGLB(buffer);
        if(this.closed) return;
        if(this.extension==='glb') {
            this.orientationGroup=new THREE.Group();this.orientationGroup.add(this.root);this.scene.add(this.orientationGroup);
        } else this.scene.add(this.root);
        this.buildControls();
        this.resizeObserver=new ResizeObserver(()=>this.resize());this.resizeObserver.observe(this.stage);
        this.listen(document,'visibilitychange',()=>{
            if(document.hidden) {cancelAnimationFrame(this.frame);this.frame=0;this.lastTime=0;}
            else this.requestRender();
        });
        this.listen(this.renderer.domElement,'webglcontextlost',event=>{
            // Usually the browser reclaiming GPU memory for a large model: release everything and
            // offer Retry (via onFailure) instead of leaving a dead canvas behind.
            event.preventDefault();this.loadError=new Error('3D graphics context was lost');
            this.stopRuntime();this.status.hidden=false;
            this.status.textContent=this.labels['graphics-memory'] || 'The 3D preview ran out of graphics memory.';
            this.container.dataset.damModelViewer='error';this.log('context_error');this.onFailure?.();
        });
        // Remove the loading bar before measuring the stage: hiding it after
        // the first fit changes the aspect underneath the initial camera.
        this.stopPreparing();
        this.status.hidden=true;this.toolbar.hidden=false;
        this.container.dataset.damModelViewer='ready';this.resize();
        // A stage that has no size yet (a modal mid-transition, a hidden tab) still gets a
        // provisional frame at aspect 1; `resize()` re-frames once the first real measurement lands.
        if(!this.framed) {this.fit();this.restorePrimaryCamera();}
        this.log('load_ready',{objects:this.entries.length,animations:this.clips?.length || 0});
    }
    async loadThreeMF(buffer) {
        this.worker=new Worker(new URL('./three-mf.worker.mjs',import.meta.url),{type:'module'});
        const data=await new Promise((resolve,reject)=>{
            this.workerReject=reject;
            this.worker.onmessage=({data})=>data.error ? reject(new Error(data.error)) : resolve(data.result);
            this.worker.onerror=()=>reject(new Error('3MF parser worker failed'));
            this.worker.postMessage({buffer},[buffer]);
        });
        this.worker.terminate();this.worker=null;this.workerReject=null;
        if(this.closed) return;
        const built=buildThreeMFScene({data});this.root=built.root;this.entries=built.entries;
        this.plates=data.plates; this.palette=data.palette;
        // A simulated bed per plate, shown and hidden with the plate selector.
        this.buildPlates=buildBuildPlates({root:this.root,entries:this.entries,plates:this.plates,printableArea:data.printableArea});
        this.log('3mf_parsed',{triangles:data.faces,plates:data.plates.length,buildPlates:this.buildPlates.length});
    }
    loadSTL(buffer) {
        // STL has no colour, UVs or units, so there is nothing to decode off-thread and
        // no plate/animation UI to build: one neutral mesh, framed like everything else.
        const name=decodeURIComponent(new URL(this.url,location.href).pathname.split('/').pop() || '') || null;
        const built=buildSTLScene({buffer,name:/\.stl$/i.test(name || '') ? name : null});
        this.root=built.root;this.entries=built.entries;
        this.log('stl_parsed',{triangles:built.triangles});
    }
    async loadGLB(buffer) {
        const manager=new THREE.LoadingManager();
        const decoderUrls={'draco_decoder.wasm':dracoWasm,'draco_wasm_wrapper.js':dracoWrapper,'basis_transcoder.wasm':basisWasm,'basis_transcoder.js':basisWrapper};
        manager.setURLModifier(url=>decoderUrls[url] || url);
        this.draco=new DRACOLoader(manager).setDecoderPath('').setDecoderConfig({type:'wasm'}).setWorkerLimit(2);
        this.ktx2=new KTX2Loader(manager).setTranscoderPath('').setWorkerLimit(2).detectSupport(this.renderer);
        const loader=new GLTFLoader(manager).setDRACOLoader(this.draco).setKTX2Loader(this.ktx2).setMeshoptDecoder(MeshoptDecoder);
        this.gltfPending=true;
        try {
            const gltf=await loader.parseAsync(buffer,new URL('.',new URL(this.url,location.href)).href);
            if(this.closed) {for(const scene of gltf.scenes) disposeModel(scene);return;}
            this.root=gltf.scene;this.extraScenes=gltf.scenes.filter(scene=>scene!==this.root);this.clips=gltf.animations;
            this.mixer=new THREE.AnimationMixer(this.root);
            // Top-level nodes keep skeletal hierarchies intact when visibility changes.
            this.entries=this.root.children.map((object,index)=>({key:String(index),name:object.name || `Object ${index+1}`,object,enabled:true}));
        } finally {
            this.gltfPending=false;
            // Terminating Draco workers mid-decode strands their promise callbacks.
            // Let an in-flight decode settle, discard its scene, then release workers.
            if(this.closed) {this.draco.dispose();this.ktx2.dispose();}
        }
    }
    buildControls() {
        button('Reset view',this.toolbar,()=>{if(!this.editingPrimary && this.primaryView?.view)this.restorePrimaryCamera();else this.fit();}).dataset.damModelReset='';
        if(this.extension==='glb' && this.primaryView?.can_edit && this.onSavePrimary) this.buildPrimaryControls();
        if(this.plates?.length) {
            const label=element('label','Plate ',this.toolbar), select=element('select','',label);select.dataset.damModelPlate='';select.setAttribute('aria-label','Plate');
            for(const plate of this.plates) {const option=element('option',plate.name || `Plate ${plate.id}`,select);option.value=plate.id;}
            const all=element('option','All plates',select);all.value='all';
            if(this.plates.length>1) select.value='all';
            this.selectedPlate=select.value;
            select.onchange=()=>{this.selectedPlate=select.value;this.applyVisibility();this.fit();this.log('plate_changed',{plate:select.value});};
        }
        const details=element('details','',this.toolbar);details.className='dam-model-objects';element('summary','Objects',details);
        const list=element('div','',details);
        for(const entry of this.entries) {
            const label=element('label','',list), check=element('input','',label);check.type='checkbox';check.checked=entry.enabled;
            element('span',entry.name,label);check.onchange=()=>{entry.enabled=check.checked;this.applyVisibility();this.requestRender();};
        }
        if(this.palette?.length) {
            const palette=element('div','',this.toolbar);palette.className='dam-model-palette';
            this.palette.forEach((color,i)=>{
                const swatch=element('span','',palette);swatch.title=`Filament ${i+1}: ${color}`;
                if(/^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(color)) swatch.style.backgroundColor=color;
            });
        }
        if(this.clips?.length) {
            const select=element('select','',this.toolbar);select.setAttribute('aria-label','Animation');select.dataset.damModelAnimation='';
            this.clips.forEach((clip,i)=>{const option=element('option',clip.name || `Animation ${i+1}`,select);option.value=String(i);});
            this.playButton=button('Pause',this.toolbar,()=>{this.playing=!this.playing;this.playButton.textContent=this.playing?'Pause':'Play';this.lastTime=0;this.requestRender();});this.playButton.dataset.damModelPlay='';
            this.scrub=element('input','',this.toolbar);this.scrub.type='range';this.scrub.min=0;this.scrub.step=.001;this.scrub.setAttribute('aria-label','Animation time');this.scrub.dataset.damModelTime='';
            this.timeLabel=element('span','',this.toolbar);this.timeLabel.className='dam-model-time';
            this.scrub.oninput=()=>{
                this.action.time=Number(this.scrub.value);this.mixer.update(0);this.updateTime();this.requestRender();
            };
            const speed=element('select','',this.toolbar);speed.setAttribute('aria-label','Playback speed');
            for(const value of [.25,.5,1,1.5,2]) {const option=element('option',`${value}×`,speed);option.value=value;option.selected=value===1;}
            speed.onchange=()=>{this.mixer.timeScale=Number(speed.value);};
            select.onchange=()=>this.selectAnimation(Number(select.value));
            this.selectAnimation(0);
        }
        element('span','Drag to orbit · Right-drag to pan · Scroll to zoom',this.toolbar).className='dam-model-hint';
        this.applyVisibility();
    }
    buildPrimaryControls() {
        this.primaryToggle=button('Set primary view',this.toolbar,()=>this.beginPrimaryEdit());
        this.primaryPanel=element('div','',this.toolbar);this.primaryPanel.className='dam-model-primary';this.primaryPanel.hidden=true;
        element('span','Drag to choose the front · Scroll to frame · Rotate to make upright',this.primaryPanel);
        button('Fit model',this.primaryPanel,()=>this.fit({preserveDirection:true}));
        for(const [label,axis,angle] of [['Tip forward 90°','x',-Math.PI/2],['Turn right 90°','y',Math.PI/2],['Roll left 90°','z',Math.PI/2],['Roll right 90°','z',-Math.PI/2]]) {
            button(label,this.primaryPanel,()=>this.rotatePrimary({axis,angle}));
        }
        // Half turns reposition a model that arrived facing away or upside down in one click.
        for(const [label,axis,flip] of [['Flip left/right 180°','y','horizontal'],['Flip up/down 180°','x','vertical']]) {
            button(label,this.primaryPanel,()=>this.rotatePrimary({axis,angle:Math.PI})).dataset.damModelFlip=flip;
        }
        button('Save primary view',this.primaryPanel,()=>this.commitPrimary(false));
        // Always built, shown only while a saved view exists: the first save of an initially unsaved
        // model reveals it in place (syncPrimaryControls), with no model reload.
        if(this.onRestorePrimary) {
            this.restoreButton=button('Restore original',this.primaryPanel,()=>this.commitPrimary(true));
            this.syncPrimaryControls();
        }
        button('Cancel',this.primaryPanel,()=>this.cancelPrimaryEdit());
        this.primaryMessage=element('span','',this.primaryPanel);this.primaryMessage.setAttribute('role','status');
    }
    syncPrimaryControls() {
        if(this.restoreButton) this.restoreButton.hidden=!canRestorePrimary({primaryView:this.primaryView,hasRestoreHandler:Boolean(this.onRestorePrimary)});
    }
    beginPrimaryEdit() {
        if(this.editingPrimary)return;
        this.editingPrimary=true;
        this.primarySnapshot={position:this.orientationGroup.position.clone(),rotation:this.orientationGroup.quaternion.clone(),
            camera:this.camera.position.clone(),target:this.controls.target.clone(),playing:this.playing};
        this.playing=false;if(this.playButton)this.playButton.textContent='Play';
        this.primaryPanel.hidden=false;this.primaryToggle.hidden=true;this.primaryMessage.textContent='';
    }
    cancelPrimaryEdit() {
        const s=this.primarySnapshot;if(!s)return;
        this.orientationGroup.position.copy(s.position);this.orientationGroup.quaternion.copy(s.rotation);
        this.camera.position.copy(s.camera);this.controls.target.copy(s.target);this.controls.update();
        this.playing=s.playing;if(this.playButton)this.playButton.textContent=this.playing?'Pause':'Play';
        this.editingPrimary=false;this.primaryPanel.hidden=true;this.primaryToggle.hidden=false;this.requestRender();
    }
    rotatePrimary({axis,angle}) {
        const direction=new THREE.Vector3(axis==='x'?1:0,axis==='y'?1:0,axis==='z'?1:0).applyQuaternion(this.camera.quaternion);
        const rotation=new THREE.Quaternion().setFromAxisAngle(direction,angle);
        this.orientationGroup.position.sub(this.controls.target).applyQuaternion(rotation).add(this.controls.target);
        this.orientationGroup.quaternion.premultiply(rotation);this.requestRender();
    }
    primaryViewPayload() {
        // The chosen viewing direction becomes +Z front, with +Y up, in the downloaded GLB.
        const inverseCamera=this.camera.quaternion.clone().invert();
        return {
            rotation:inverseCamera.clone().multiply(this.orientationGroup.quaternion).normalize().toArray(),
            translation:this.orientationGroup.position.clone().sub(this.controls.target).applyQuaternion(inverseCamera).toArray(),
            view:{distance:this.camera.position.distanceTo(this.controls.target),aspect:this.camera.aspect},
        };
    }
    /**
     * A saved view is adopted in place instead of reloading the model, by putting the viewer in
     * exactly the state a fresh load of the newly baked file would have: an identity orientation
     * group over a model that already carries the saved orientation (the saved delta, wrapped
     * around the loaded meshes so the picture does not jump), and the camera where
     * restorePrimaryCamera() places it. The next delta is then composed by the server, and
     * computed here, exactly as after a reload.
     */
    adoptPrimaryView(primaryView,payload) {
        this.primaryView=primaryView;
        this.syncPrimaryControls();
        const baked=new THREE.Group();
        baked.quaternion.fromArray(payload.rotation);baked.position.fromArray(payload.translation);
        for(const child of [...this.orientationGroup.children]) baked.add(child);
        this.orientationGroup.add(baked);
        this.orientationGroup.position.set(0,0,0);this.orientationGroup.quaternion.identity();
        this.fit();this.restorePrimaryCamera();
        this.log('primary_adopted',{restorable:canRestorePrimary({primaryView,hasRestoreHandler:Boolean(this.onRestorePrimary)})});
    }
    async commitPrimary(restore) {
        if(this.primaryBusy)return;
        this.primaryBusy=true;this.controls.enabled=false;
        const buttons=[...this.toolbar.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);
        this.primaryMessage.textContent=restore?'Restoring original…':'Saving primary view…';
        try {
            if(restore)await this.onRestorePrimary();else await this.onSavePrimary(this.primaryViewPayload());
            if(!this.closed) {this.primaryPanel.hidden=true;this.primaryToggle.hidden=false;this.editingPrimary=false;}
            this.log(restore?'primary_restored':'primary_saved');
        } catch(error) {
            if(!this.closed)this.primaryMessage.textContent=error.response?.data?.message || error.message || 'Could not save. Try again.';
            this.log('primary_save_error',{name:error.name});
        } finally {
            this.primaryBusy=false;
            if(!this.closed) {this.controls.enabled=true;buttons.forEach(b=>b.disabled=false);}
        }
    }
    restorePrimaryCamera() {
        const view=this.primaryView?.view;if(!view)return;
        const fov=THREE.MathUtils.degToRad(this.camera.fov);
        const halfAngle=aspect=>Math.min(fov,2*Math.atan(Math.tan(fov/2)*aspect))/2;
        const distance=view.distance*Math.sin(halfAngle(view.aspect))/Math.sin(halfAngle(this.camera.aspect));
        this.controls.target.set(0,0,0);this.camera.position.set(0,0,distance);
        this.controls.minDistance=Math.min(this.controls.minDistance,distance/100);
        this.controls.maxDistance=Math.max(this.controls.maxDistance,distance*20);
        this.camera.near=Math.min(this.camera.near,distance/1000);this.camera.far=Math.max(this.camera.far,distance*100);
        this.camera.updateProjectionMatrix();this.controls.update();this.requestRender();
    }
    selectAnimation(index) {
        this.mixer.stopAllAction();this.action=this.mixer.clipAction(this.clips[index]);this.action.reset().play();
        this.scrub.max=this.clips[index].duration;this.scrub.value=0;this.playing=true;this.playButton.textContent='Pause';
        // The label is sized for its longest reading once, so "9.9 / 12.0s" rolling to "10.0 / 12.0s"
        // never widens the toolbar, re-wraps it and resizes the stage under a playing animation.
        this.timeLabel.style.minWidth=`${this.formatTime(this.clips[index].duration).length}ch`;this.updateTime();
        this.lastTime=0;this.requestRender();
    }
    applyVisibility() {
        const plate=this.plates?.find(p=>p.id===this.selectedPlate);
        for(const entry of this.entries) entry.object.visible=entry.enabled && (!plate || plate.instances.includes(entry.key));
        for(const bed of this.buildPlates || []) bed.object.visible=!plate || bed.id===plate.id;
    }
    fit({preserveDirection=false}={}) {
        if(!this.root) return;
        this.root.updateWorldMatrix(true,true);
        const box=new THREE.Box3();
        // Box3.setFromObject includes hidden meshes, so walk visible geometry explicitly.
        // Simulated build plates are framing furniture, not the model: they stay out of the box.
        this.root.traverseVisible(object=>{
            if(object.isMesh && !object.userData.damBuildPlate) {
                if(object.isSkinnedMesh) {object.computeBoundingBox();box.union(object.boundingBox.clone().applyMatrix4(object.matrixWorld));}
                else {object.geometry.computeBoundingBox();box.union(object.geometry.boundingBox.clone().applyMatrix4(object.matrixWorld));}
            }
        });
        if(box.isEmpty()) {this.requestRender();return;}
        const center=box.getCenter(new THREE.Vector3()), size=box.getSize(new THREE.Vector3());
        const radius=Math.max(size.length()/2,.01);
        const fov=THREE.MathUtils.degToRad(this.camera.fov);
        const distance=radius/Math.sin(Math.min(fov,2*Math.atan(Math.tan(fov/2)*this.camera.aspect))/2)*1.15;
        const direction=preserveDirection ? this.camera.position.clone().sub(this.controls.target).normalize() : new THREE.Vector3(1,.7,1).normalize();
        this.camera.position.copy(center).add(direction.multiplyScalar(distance));
        this.camera.near=Math.max(radius/1000,.00001);this.camera.far=Math.max(distance*100,radius*1000);this.camera.updateProjectionMatrix();
        this.controls.target.copy(center);this.controls.minDistance=radius*.02;this.controls.maxDistance=distance*20;this.controls.update();
        this.requestRender();
    }
    resize() {
        if(this.closed || !this.renderer) return;
        const {width,height}=this.stage.getBoundingClientRect();if(width<1 || height<1)return;
        this.renderer.setSize(width,height,false);this.camera.aspect=width/height;this.camera.updateProjectionMatrix();
        if(!this.framed) {this.framed=true;this.fit();this.restorePrimaryCamera();}
        this.requestRender();
    }
    formatTime(time) {
        const total=Number(this.scrub.max).toFixed(1);
        return `${time.toFixed(1).padStart(total.length)} / ${total}s`;
    }
    updateTime() {
        if(!this.action)return;
        this.scrub.value=this.action.time;
        this.timeLabel.textContent=this.formatTime(this.action.time);
    }
    requestRender() {
        if(this.closed || !this.renderer || document.hidden || this.frame) return;
        this.frame=requestAnimationFrame(time=>{
            this.frame=0;if(this.closed || !this.renderer)return;
            if(this.playing && this.mixer) {this.mixer.update(this.lastTime?Math.min((time-this.lastTime)/1000,.1):0);this.updateTime();}
            this.lastTime=time;this.renderer.render(this.scene,this.camera);
            if(this.playing)this.requestRender();
        });
    }
    stopRuntime() {
        this.abort.abort();cancelAnimationFrame(this.frame);this.frame=0;this.playing=false;
        this.worker?.terminate();this.worker=null;this.workerReject?.(new DOMException('Preview closed','AbortError'));this.workerReject=null;
        this.resizeObserver?.disconnect();for(const remove of this.listeners)remove();this.listeners=[];
        this.controls?.dispose();this.mixer?.stopAllAction();if(this.root)this.mixer?.uncacheRoot(this.root);
        disposeModel(this.root);for(const scene of this.extraScenes || [])disposeModel(scene);
        if(!this.gltfPending) {this.draco?.dispose();this.ktx2?.dispose();}
        this.environment?.dispose();
        this.renderer?.dispose();this.renderer?.forceContextLoss();this.renderer=null;this.root=null;
        this.stage.replaceChildren();this.toolbar.replaceChildren();this.toolbar.hidden=true;
    }
    dispose() {
        if(this.closed)return;
        this.closed=true;clearInterval(this.prepareTimer);this.stopRuntime();this.log('disposed');this.container.replaceChildren();this.container.dataset.damModelViewer='closed';
    }
}
