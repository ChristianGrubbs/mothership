@once
<script type="text/x-template" id="v-dam-model-viewer-template">
    <div class="relative w-full h-full min-h-0">
        <div v-show="gate === 'loading'" ref="host" class="w-full h-full"></div>
        <div v-if="gate === 'tap' || gate === 'confirm'" class="flex h-full w-full flex-col items-center justify-center gap-4 bg-gray-100 p-4 text-center dark:bg-cherry-800">
            <img v-if="thumbnailUrl && !thumbnailFailed" :src="thumbnailUrl" alt="" class="max-h-[60%] max-w-full object-contain" v-on:error="thumbnailFailed = true" />
            <p v-if="gate === 'confirm'" class="max-w-sm text-sm text-gray-700 dark:text-gray-200">@{{ confirmText }}</p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <button v-if="gate === 'tap'" type="button" class="primary-button" data-dam-model-load @click="begin">@{{ loadPreviewText }}</button>
                <template v-else>
                    <button type="button" class="primary-button" data-dam-model-load @click="confirmLoad">@{{ labels.load }}</button>
                    <a v-if="downloadUrl" :href="downloadUrl" download data-dam-download :data-dam-download-name="downloadName" :data-dam-download-thumb="thumbnailUrl" class="secondary-button">@{{ labels.download }}</a>
                </template>
            </div>
        </div>
        <button v-if="failed" type="button" class="primary-button absolute bottom-4 left-4" @click="begin">Retry 3D preview</button>
    </div>
</script>
<script type="module">
    // WebGL and loader objects must never enter Vue's deep reactive proxy graph.
    const modelRuntimes = new WeakMap();
    const modelViewerLabels = @js(trans('dam::app.model-viewer'));
    // The checksum a capture may be posted against: the bytes this viewer loaded (read before the download, moved to the
    // new checksum when a primary-view save adopts its result in the scene), and only while the server still holds them.
    // Any difference means the scene on screen is a superseded revision: a picture of revision A is never stored as B's.
    const capturableChecksum = (loaded, current) => {
        const a = typeof loaded === 'string' ? loaded.toLowerCase() : '', b = typeof current === 'string' ? current.toLowerCase() : '';
        return /^[a-f0-9]{64}$/.test(a) && a === b ? a : null;
    };
    app.component('v-dam-model-viewer', {
        template: '#v-dam-model-viewer-template',
        props: {
            url: {type:String,required:true}, extension: {type:String,required:true}, assetId: {type:[Number,String],default:null},
            // A file of a stack: captured per file, never against the stack's own (cover) thumbnail.
            fileId: {type:[Number,String],default:null},
            // File size in bytes (progress fallback and phone guard) and its display label.
            sizeBytes: {type:Number,default:0}, sizeLabel: {type:String,default:''},
            // Public pages wait for a tap, so a crash-and-reload can never restart the download by itself.
            deferLoad: {type:Boolean,default:false}, thumbnailUrl: {type:String,default:''}, downloadUrl: {type:String,default:''},
            // The file name the "Preparing download" card shows for the download link.
            downloadName: {type:String,default:''},
        },
        data() { return { failed:false, gate:this.deferLoad ? 'tap' : 'loading', thumbnailFailed:false, labels:modelViewerLabels }; },
        computed: {
            sizeText() {
                if(this.sizeLabel) return this.sizeLabel;
                return this.sizeBytes>0 ? this.labels['size-megabytes'].replace(':size',(this.sizeBytes/1048576).toFixed(1)) : '';
            },
            loadPreviewText() {
                return this.sizeText ? this.labels['load-preview'].replace(':size',this.sizeText) : this.labels['load-preview-unsized'];
            },
            confirmText() { return this.labels['phone-confirm'].replace(':size',this.sizeText); },
        },
        mounted() { if(this.gate==='loading') this.begin(); },
        beforeUnmount() { this.stop(); },
        watch: { url() { this.restart(); }, extension() { this.restart(); }, assetId() { this.restart(); } },
        methods: {
            restart() { if(this.gate!=='tap') this.begin(); },
            // Phones ask first for a large model: it is held in memory whole, and iOS kills the tab.
            async begin() {
                // A second tap while the policy module is still loading must not start a second load.
                if(this.gating) return;
                this.gating=true;
                try {
                    this.stop();this.failed=false;
                    const policy=await window.loadDamModelPolicy();
                    if(!this.confirmed && policy.needsPhoneConfirm({sizeBytes:this.sizeBytes,coarsePointer:policy.isCoarsePointer()})) {
                        this.gate='confirm';return;
                    }
                    this.gate='loading';
                    await this.$nextTick();
                } finally { this.gating=false; }
                this.start();
            },
            confirmLoad() { this.confirmed=true;this.begin(); },
            stop() {
                const state=modelRuntimes.get(this);
                if(state) { state.cancelled=true; state.viewer?.dispose(); modelRuntimes.delete(this); }
            },
            async start() {
                this.stop();this.failed=false;
                const state={cancelled:false,viewer:null};modelRuntimes.set(this,state);
                this.$refs.host.textContent='Loading 3D viewer…';
                try {
                    const {ModelViewer}=await window.loadDamModelViewer();
                    if(state.cancelled)return;
                    const endpoint=this.extension.toLowerCase()==='glb' && this.assetId
                        ? @js(route('admin.dam.assets.primary_view.show', ['id'=>'__ASSET__'])).replace('__ASSET__',this.assetId) : null;
                    let primaryView=endpoint ? (await window.axios.get(endpoint)).data : null;
                    if(state.cancelled)return;
                    // A saved or restored primary view changes what the model's
                    // thumbnail should show, so the reload that follows re-captures
                    // it even though one already exists.
                    // Saving adopts the new view in the scene that is already loaded: no second download.
                    const save=async payload=>{
                        const {data}=await window.axios.post(endpoint,{...payload,revision:primaryView.revision});
                        primaryView=data;
                        this.recaptureThumbnail=true;
                        // The save rewrote the model into new bytes, and the loaded scene now shows exactly that result.
                        state.loadedChecksum=typeof data?.checksum==='string' ? data.checksum : null;
                        if(state.cancelled)return;
                        state.viewer.adoptPrimaryView(data,payload);
                        this.captureThumbnail(state);
                    };
                    const restore=async()=>{
                        await window.axios.delete(endpoint,{data:{revision:primaryView.revision}});
                        this.recaptureThumbnail=true;
                        if(!state.cancelled)await this.start();
                    };
                    // Which bytes this scene shows: read before the download starts, so a replacement published while the model
                    // loads (or before the capture handshake) makes the checksum at capture time differ and nothing is stored.
                    state.loadedChecksum=await this.readChecksum();
                    if(state.cancelled)return;
                    const modelUrl=await this.pinModelUrl(state);
                    if(state.cancelled)return;
                    this.$refs.host.replaceChildren();
                    // The URL stays stable: the asset routes revalidate on every use, so a changed file is
                    // fetched and an unchanged one is not downloaded again.
                    state.viewer=new ModelViewer({container:this.$refs.host,url:modelUrl,extension:this.extension,primaryView,onSavePrimary:save,onRestorePrimary:restore,labels:this.labels,
                        expectedBytes:this.sizeBytes,onFailure:()=>{if(!state.cancelled)this.failed=true;}});
                    await state.viewer.ready;
                    if(!state.cancelled)this.failed=this.$refs.host.dataset.damModelViewer==='error';
                    if(!state.cancelled && !this.failed) await this.captureThumbnail(state);
                } catch(error) {
                    if(!state.cancelled) {this.$refs.host.textContent='Unable to load the 3D viewer. Retry to load it again.';this.failed=true;}
                }
            },
            /**
             * Opening a model that has no thumbnail yet earns it one, straight
             * off the renderer that is already showing it (ADR 0006) — never a
             * second download, and never a blocked preview: every failure here
             * is swallowed, because a missing card image is not worth an error
             * in front of someone who only asked to look at a model.
             */
            captureEndpoint(target) {
                return (target.kind==='file'
                    ? @js(route('admin.dam.assets.file_model_thumbnail.show', ['assetFile'=>'__TARGET__']))
                    : @js(route('admin.dam.assets.model_thumbnail.show', ['id'=>'__TARGET__']))).replace('__TARGET__',target.id);
            },
            // The two checksum reads around a download cannot prove which bytes arrived (replace B, restore A, both reads say A).
            // So the download itself carries the identity: the asset route answers 409 unless the entry still holds those bytes.
            // Anything the viewer cannot pin this way (another route) is never captured as the asset's picture.
            async pinModelUrl(state) {
                const policy=await window.loadDamModelPolicy();
                const target=policy.thumbnailTarget({assetId:this.assetId,fileId:this.fileId});
                // A file of a stack is a single immutable revision: its checksum cannot change under the download.
                if(!target || target.kind!=='asset')return this.url;
                const checksum=capturableChecksum(state.loadedChecksum,state.loadedChecksum);
                const route=@js(route('admin.dam.assets.download', ['id'=>'__ASSET__'])).replace('__ASSET__',target.id);
                const [path,query='']=this.url.split('?');
                const base=route.replace(/^[a-z]+:\/\/[^/]+/i,'');
                if(checksum===null || path.replace(/^[a-z]+:\/\/[^/]+/i,'')!==base){state.loadedChecksum=null;return this.url;}
                return path+'?'+(query ? query+'&' : '')+'sha256='+checksum;
            },
            async readChecksum() {
                const policy=await window.loadDamModelPolicy();
                const target=policy.thumbnailTarget({assetId:this.assetId,fileId:this.fileId});
                if(!target)return null;
                try { return (await window.axios.get(this.captureEndpoint(target))).data?.checksum ?? null; } catch { return null; }
            },
            async captureThumbnail(state) {
                const policy=await window.loadDamModelPolicy();
                const target=policy.thumbnailTarget({assetId:this.assetId,fileId:this.fileId});
                if(!target)return;
                // Automatic capture adds GPU work on a phone that is already under memory pressure;
                // an explicit primary-view save (recaptureThumbnail) still replaces the card image.
                if(this.recaptureThumbnail!==true && policy.isCoarsePointer())return;
                // Set by a primary-view save/restore: replace the existing capture instead of keeping it.
                // Cleared only once the server has answered, so a transient failure keeps the request alive.
                const replace=this.recaptureThumbnail===true;
                const endpoint=this.captureEndpoint(target);
                try {
                    const {data}=await window.axios.get(endpoint);
                    if(state.cancelled)return;
                    if(!data?.can_edit || !data.checksum) {this.recaptureThumbnail=false;return;}
                    // The model changed since this scene loaded: its picture is not a capture of the current bytes.
                    const checksum=capturableChecksum(state.loadedChecksum,data.checksum);
                    if(checksum===null) {this.recaptureThumbnail=false;return;}
                    // Someone else's item keeps its picture: a person limited to their own files sends nothing for it.
                    if(data.can_capture===false || (replace && data.can_replace===false)) {this.recaptureThumbnail=false;return;}
                    if(data.has_thumbnail && !replace)return;
                    const {captureFromViewer,saveLatestCapture}=await window.loadDamModelThumbnails();
                    if(state.cancelled || state.viewer!==modelRuntimes.get(this)?.viewer)return;
                    // The save takes its place in line before the encode starts; a newer save for the same model supersedes
                    // it, even if this one's encode finishes later (it then drops itself, and so does a retry still waiting).
                    const result=await saveLatestCapture({
                        key:`${target.kind}:${target.id}`,
                        encode:()=>captureFromViewer(state.viewer),
                        post:blob=>{
                            const form=new FormData();
                            form.append('image',blob,`model-${target.kind}-${target.id}.jpg`);
                            form.append('checksum',checksum);
                            if(replace)form.append('replace','1');
                            return window.axios.post(endpoint,form);
                        },
                        shouldStop:()=>state.cancelled,
                    });
                    if(!result)return;
                    if(replace)this.recaptureThumbnail=false;
                    const saved=result.data;
                    if(result.current && !state.cancelled && saved?.stored && saved.thumbnail_url) {
                        // The stack's tiles and strip show a file's picture; the Library card shows an asset's.
                        if(target.kind==='file')this.$emitter.emit('dam:file-thumbnail-updated',{fileId:target.id,url:saved.thumbnail_url});
                        else this.$emitter.emit('dam:thumbnail-updated',{id:target.id,url:saved.thumbnail_url});
                    }
                } catch { /* A thumbnail is a nicety; the preview it came from still works. */ }
            }
        }
    });
</script>
@endonce
