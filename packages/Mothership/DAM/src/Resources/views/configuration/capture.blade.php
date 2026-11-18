<x-admin::layouts>
    <x-slot:title>Details layouts &amp; capture profiles</x-slot:title>
    <v-capture-settings :initial='@json($initial, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'></v-capture-settings>
    @pushOnce('scripts')
    <script type="text/x-template" id="v-capture-settings-template">
        <div class="mx-auto max-w-6xl pb-10" data-capture-settings>
            <a class="text-sm text-primary-600" href="{{ route('admin.dam.configuration.index') }}">← DAM configuration</a>
            <h1 class="mt-3 text-2xl font-semibold dark:text-white">Details layouts &amp; capture profiles</h1>
            <p class="mt-2 text-sm text-gray-500">Make each asset easy to scan. Keep the capture together.</p>
            <p v-if="message" role="status" class="my-4 rounded-lg bg-gray-100 p-3 dark:bg-cherry-800">@{{ message }}</p>
            <fieldset :disabled="busy || !initial.can_update" class="mt-6 grid gap-6">
                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-cherry-900">
                    <div class="flex items-start justify-between gap-4">
                        <div><h2 class="text-lg font-semibold">Configure Details</h2><p class="mt-1 text-sm text-gray-500">Choose the fields shown in the right Details pane and preview. Saving applies this order to every existing asset and every future file type. Full metadata stays in Properties.</p></div>
                        <div class="grid justify-items-end gap-2">
                            <button class="primary-button whitespace-nowrap" @click="saveLayouts" :aria-busy="feedback.layouts.state === 'saving'">@{{ feedback.layouts.state === 'saving' ? 'Saving…' : 'Save Details' }}</button>
                            @include('dam::components.save-feedback', ['action' => 'layouts'])
                        </div>
                    </div>
                    <p class="mt-5 text-sm font-medium">All file types · one global layout</p>
                    <div v-if="layout" class="mt-5 grid gap-5 md:grid-cols-2">
                        <div>
                            <p class="my-4 text-xs text-gray-500">Only fields the asset has a value for are shown; empty rows are hidden automatically.</p>
                            <label class="block text-sm font-medium" for="field-search">Available fields</label>
                            <input id="field-search" v-model="fieldSearch" class="my-2 w-full rounded border p-2 dark:bg-cherry-800" placeholder="Find a field…">
                            <div class="max-h-72 overflow-y-auto rounded border dark:border-gray-700">
                                <label v-for="(label, key) in filteredFields" :key="key" class="flex items-center gap-3 border-b p-2 text-sm dark:border-gray-700">
                                    <input type="checkbox" :checked="layout.fields.includes(key)" @change="toggleField(key)">@{{ label }}
                                </label>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium">Visible fields · drag to reorder</h3>
                            <ol class="mt-2 grid gap-2">
                                <li v-for="(key, i) in layout.fields" :key="key" draggable="true" @dragstart="dragIndex=i" @dragover.prevent @drop.prevent="reorder(layout.fields, i)" class="flex items-center gap-2 rounded border p-2 text-sm dark:border-gray-700">
                                    <span aria-hidden="true" class="cursor-grab text-gray-400">⠿</span><span class="flex-1">@{{ fields[key] || key }}</span>
                                    <button @click="move(layout.fields, i, -1)" :disabled="i===0" aria-label="Move field up">↑</button>
                                    <button @click="move(layout.fields, i, 1)" :disabled="i===layout.fields.length-1" aria-label="Move field down">↓</button>
                                    <button @click="layout.fields.splice(i,1)" aria-label="Remove field">×</button>
                                </li>
                            </ol>
                            <p v-if="!layout.fields.length" class="mt-3 text-sm text-gray-500">Select fields on the left.</p>
                        </div>
                    </div>
                    <p v-else class="mt-5 text-sm text-gray-500">No custom layouts. The existing information pane is used.</p>
                </section>
                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-cherry-900">
                    <div class="flex justify-between gap-4"><div><h2 class="text-lg font-semibold">Capture profiles</h2><p class="mt-1 text-sm text-gray-500">Group the same filename stem in the same folder. Highest available priority becomes the visible asset.</p></div><button class="secondary-button" @click="newProfile">+ New profile</button></div>
                    <div class="mt-5 flex flex-wrap gap-2"><button v-for="p in profiles" :key="p.id" class="secondary-button" @click="editProfile(p)">@{{ p.name }} @{{ p.enabled ? '' : '(manual)' }}</button></div>
                    <div class="mt-5 grid gap-5 md:grid-cols-2">
                        <div>
                            <label for="profile-name" class="block text-sm font-medium">Capture type name</label>
                            <input id="profile-name" v-model.trim="draft.name" class="mt-2 w-full rounded border p-2 dark:bg-cherry-800" placeholder="Drone footage">
                            <label for="profile-extensions" class="mt-4 block text-sm font-medium">Matching extensions</label>
                            <input id="profile-extensions" v-model="extensionText" @change="syncExtensions" class="mt-2 w-full rounded border p-2 dark:bg-cherry-800" placeholder="osv, mp4, jpg, srt, lrf">
                            <label class="mt-4 flex gap-2 text-sm"><input type="checkbox" v-model="draft.enabled">Automatically group new uploads</label>
                            <p class="mt-2 text-xs text-gray-500">Overlapping profiles need a manual choice. Changes to a profile do not regroup the existing library until you apply a preview.</p>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium">Primary file priority · highest first</h3>
                            <ol class="mt-2 grid gap-2">
                                <li v-for="(ext,i) in draft.priority" :key="ext" draggable="true" @dragstart="dragIndex=i" @dragover.prevent @drop.prevent="reorder(draft.priority,i)" class="flex items-center gap-2 rounded border p-2 text-sm dark:border-gray-700">
                                    <span class="cursor-grab text-gray-400">⠿</span><span class="flex-1">@{{ ext.toUpperCase() }}</span>
                                    <button @click="move(draft.priority,i,-1)" :disabled="i===0" aria-label="Move priority up">↑</button><button @click="move(draft.priority,i,1)" :disabled="i===draft.priority.length-1" aria-label="Move priority down">↓</button><button @click="draft.priority.splice(i,1)" aria-label="Use only as companion">×</button>
                                </li>
                            </ol>
                            <p class="mt-3 text-xs text-gray-500">Companion-only extensions</p>
                            <div class="mt-2 flex flex-wrap gap-2"><button v-for="ext in companions" :key="ext" class="secondary-button" @click="draft.priority.push(ext)">+ @{{ ext }}</button></div>
                        </div>
                    </div>
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <button class="primary-button" @click="saveProfile" :aria-busy="feedback.profile.state === 'saving'">@{{ feedback.profile.state === 'saving' ? 'Saving…' : 'Save capture profile' }}</button>
                        @include('dam::components.save-feedback', ['action' => 'profile'])
                    </div>
                </section>
                <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-cherry-900">
                    <h2 class="text-lg font-semibold">Group existing files</h2><p class="mt-1 text-sm text-gray-500">Review the exact groups before applying. No files are deleted or moved. Ungrouped captures stay separate.</p>
                    <div class="mt-5 flex flex-wrap items-end gap-3">
                        <label class="grid gap-2 text-sm">Saved profile<select v-model="previewProfile" @change="preview=[]" class="rounded border p-2 dark:bg-cherry-800"><option value="">Choose profile</option><option v-for="p in profiles" :value="p.id">@{{ p.name }}</option></select></label>
                        <label class="grid gap-2 text-sm">Folder<select v-model="previewDirectory" @change="preview=[]" class="max-w-md rounded border p-2 dark:bg-cherry-800"><option value="">Choose folder</option><option v-for="d in initial.directories" :value="d.id">@{{ d.name }}</option></select></label>
                        <button class="secondary-button" @click="loadPreview" :disabled="!previewProfile || !previewDirectory">@{{ feedback.preview.state === 'saving' ? 'Loading…' : 'Preview groups' }}</button>
                    </div>
                    @include('dam::components.save-feedback', ['action' => 'preview'])
                    <div v-if="preview.length" class="mt-5">
                        <div v-for="g in preview" :key="g.fingerprint" class="border-t py-3 dark:border-gray-700"><p class="font-medium">@{{ g.files[g.asset_ids.indexOf(g.primary_asset_id)] }} <span class="text-xs text-gray-500">Primary</span></p><p class="mt-1 text-sm text-gray-500">@{{ g.files.join(' · ') }}</p></div>
                        <button class="primary-button mt-4" @click="applyPreview">@{{ feedback.apply.state === 'saving' ? 'Applying…' : 'Apply ' + preview.length + ' groups' }}</button>
                    </div>
                    @include('dam::components.save-feedback', ['action' => 'apply'])
                </section>
            </fieldset>
        </div>
    </script>
    <script type="module">
    app.component('v-capture-settings', {
        template: '#v-capture-settings-template', props: ['initial'],
        data() {
            const layouts = JSON.parse(JSON.stringify(this.initial.layouts));
            if (!layouts.some(layout => layout.match === '*')) layouts.push({match:'*', fields:['file_name','title','file_size_human','extension','mime_type','created_at','updated_at'], hide_empty:true});
            return { layouts, fields: this.initial.fields, profiles: this.initial.profiles, fieldSearch: '', dragIndex: null, busy: false, feedback: {layouts:{state:'idle',message:''}, profile:{state:'idle',message:''}, apply:{state:'idle',message:''}, preview:{state:'idle',message:''}}, message: '', draft: {name:'',extensions:['osv','mp4','jpg','srt','lrf'],priority:['osv','mp4','jpg'],enabled:false}, extensionText:'osv, mp4, jpg, srt, lrf', previewProfile:'', previewDirectory:'', preview:[] };
        },
        watch: {
            layouts: {deep:true, handler() { this.clearSaved('layouts'); }},
            draft: {deep:true, handler() { this.clearSaved('profile'); }},
            extensionText() { this.clearSaved('profile'); }
        },
        computed: {
            layout() { return this.layouts.find(layout => layout.match === '*'); },
            filteredFields() { return Object.fromEntries(Object.entries(this.fields).filter(([key,label]) => (key+' '+label).toLowerCase().includes(this.fieldSearch.toLowerCase()))); },
            companions() { return this.draft.extensions.filter(e => !this.draft.priority.includes(e)); }
        },
        methods: {
            async run(key, action, successMessage = '') {
                if (this.busy) return;
                this.busy = true;
                this.message = '';
                this.feedback[key] = {state:'saving', message:key === 'preview' ? 'Loading…' : key === 'apply' ? 'Applying…' : 'Saving…'};
                try {
                    await action();
                    const message = typeof successMessage === 'function' ? successMessage() : successMessage;
                    this.feedback[key] = {state:'saved', message};
                    if (message) this.$emitter.emit('add-flash', {type:'success', message});
                } catch (error) {
                    const message = Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Could not save. Your edits are still here. Try again.';
                    this.feedback[key] = {state:'error', message};
                    this.$emitter.emit('add-flash', {type:'error', message});
                } finally {
                    this.busy = false;
                }
            },
            clearSaved(key) { if (this.feedback[key].state === 'saved') this.feedback[key] = {state:'idle', message:''}; },
            toggleField(key) { const i=this.layout.fields.indexOf(key); if(i<0)this.layout.fields.push(key); else this.layout.fields.splice(i,1); },
            move(list,i,direction) { const next=i+direction; if(next<0 || next>=list.length)return; list.splice(next,0,...list.splice(i,1)); },
            reorder(list,i) { if(this.dragIndex===null)return; list.splice(i,0,...list.splice(this.dragIndex,1)); this.dragIndex=null; },
            saveLayouts() { this.run('layouts', async()=>{ await this.$axios.put(@js(route('admin.dam.configuration.layouts')), {global:true, layouts:this.layouts}); }, 'Details saved for all existing assets and future file types.'); },
            newProfile() { this.draft={name:'',extensions:['osv','mp4','jpg','srt','lrf'],priority:['osv','mp4','jpg'],enabled:false}; this.extensionText=this.draft.extensions.join(', '); },
            editProfile(p) { this.draft=JSON.parse(JSON.stringify(p)); this.extensionText=p.extensions.join(', '); },
            syncExtensions() { this.draft.extensions=[...new Set(this.extensionText.toLowerCase().split(/[\s,]+/).map(e=>e.replace(/^\./,'')).filter(Boolean))]; this.draft.priority=this.draft.priority.filter(e=>this.draft.extensions.includes(e)); },
            saveProfile() { if (this.busy) return; this.syncExtensions(); this.run('profile', async()=>{ const url=this.draft.id ? @js(route('admin.dam.configuration.profiles.update', '__id__')).replace('__id__',this.draft.id) : @js(route('admin.dam.configuration.profiles.store')); const {data}=await this.$axios[this.draft.id?'put':'post'](url,this.draft); const i=this.profiles.findIndex(p=>p.id===data.profile.id); if(i<0)this.profiles.push(data.profile);else this.profiles.splice(i,1,data.profile); this.editProfile(data.profile); this.preview=[]; }, () => 'Capture profile “'+this.draft.name+'” saved.'); },
            loadPreview() { if (this.busy) return; this.preview=[];this.run('preview', async()=>{const {data}=await this.$axios.post(@js(route('admin.dam.configuration.capture.preview')),{profile_id:this.previewProfile,directory_id:this.previewDirectory});this.preview=data.groups;this.message=data.groups.length ? 'Review the primary and companions below.' : 'No eligible groups found in this folder.';}); },
            applyPreview() { this.run('apply', async()=>{await this.$axios.post(@js(route('admin.dam.configuration.capture.apply')),{profile_id:this.previewProfile,directory_id:this.previewDirectory,groups:this.preview});this.preview=[];}, 'Groups applied. Companion files are available in the Files tab.'); }
        }
    });
    </script>
    @endPushOnce
</x-admin::layouts>
