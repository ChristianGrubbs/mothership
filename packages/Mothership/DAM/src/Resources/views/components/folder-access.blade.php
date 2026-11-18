<v-folder-access></v-folder-access>
@pushOnce('scripts')
<script type="text/x-template" id="v-folder-access-template">
    <dialog ref="dialog" aria-labelledby="folder-access-title" @cancel.prevent="close" class="rounded-lg border bg-white p-0 text-zinc-700 dark:border-gray-700 dark:bg-cherry-900 dark:text-white" style="width:min(680px,94vw);max-height:90vh;margin:auto">
        <div class="flex items-center justify-between gap-4 border-b p-4 dark:border-gray-700">
            <div><h2 id="folder-access-title" class="text-lg font-semibold">Manage access</h2><p class="mt-1 text-sm">@{{ folder.name }}</p></div>
            <button type="button" @click="close" :disabled="saving" aria-label="Close access dialog" class="secondary-button">Close</button>
        </div>
        <div class="p-4">
            <p v-if="loading" role="status">Loading access…</p>
            <p v-if="error" role="alert" class="mb-3 text-sm text-red-600">@{{ error }}</p>
            <p v-if="saved" role="status" class="mb-3 text-sm text-green-600">Access saved.</p>
            <template v-if="loaded">
                <section class="mb-5" aria-labelledby="folder-access-people-title" data-dam-access-people>
                    <h3 id="folder-access-people-title" class="text-sm font-semibold">@lang('dam::app.admin.dam.access.people-heading')</h3>
                    <p class="mt-1 text-xs text-gray-500">@lang('dam::app.admin.dam.access.people-hint')</p>
                    <ul class="mt-2 max-h-48 overflow-y-auto rounded border dark:border-gray-700">
                        <li v-for="person in people" :key="person.id" class="flex flex-wrap items-center gap-2 border-b p-2 text-sm last:border-b-0 dark:border-gray-700" data-dam-access-person>
                            <span class="font-medium">@{{ person.name }}</span>
                            <span class="text-xs text-gray-500">@{{ person.email }}</span>
                            <span class="flex flex-1 flex-wrap justify-end gap-1">
                                <span v-for="reason in person.reasons" :key="reason" class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-cherry-800 dark:text-gray-300">@{{ reason }}</span>
                            </span>
                        </li>
                    </ul>
                </section>
                <p class="mb-4 text-sm text-gray-500">Grant access to this folder. Each user's role still controls actions such as editing and deleting.</p>
                <fieldset :disabled="saving">
                    <label for="access-search" class="block text-sm font-medium">Add a user or group</label>
                    <input id="access-search" v-model="search" @input="saved=false" placeholder="Search names or email…" class="mt-2 w-full rounded border p-2 dark:bg-cherry-800">
                    <div class="mt-2 max-h-40 overflow-y-auto rounded border dark:border-gray-700">
                        <button v-for="item in matches" :key="item.type+item.id" type="button" @click="add(item)" class="flex w-full items-center justify-between gap-3 border-b p-2 text-left text-sm dark:border-gray-700">
                            <span>@{{ item.name }} <span class="text-xs text-gray-500">@{{ item.email || '' }}</span></span>
                            <span class="text-xs text-gray-500">@{{ item.type === 'user' ? 'User' : 'Group' }} · @{{ hasAccessLabel(item) || 'Add' }}</span>
                        </button>
                        <p v-if="!matches.length" class="p-2 text-sm text-gray-500">No matching users or groups.</p>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Showing up to 20 matches. <a href="{{ route('admin.settings.groups.index') }}" target="_blank" rel="noopener" class="underline">Manage groups</a> · <button type="button" class="underline" @click="refreshChoices">Refresh list</button></p>
                    <h3 class="mt-5 text-sm font-semibold">Granted on this folder</h3>
                    <p v-if="!grants.length" class="mt-2 text-sm text-gray-500">No direct user or group grants.</p>
                    <p v-if="removedCount" class="mt-2 text-sm text-amber-700 dark:text-amber-300" data-dam-access-unsaved>@{{ removedCount === 1 ? '1 removal is' : removedCount + ' removals are' }} not saved yet.</p>
                    <div v-for="item in grants" :key="item.type+item.id" class="mt-2 flex flex-wrap items-center gap-3 rounded border p-3 text-sm dark:border-gray-700">
                        <div class="flex-1"><span class="font-medium">@{{ item.name }}</span><span class="ml-2 text-xs text-gray-500">@{{ item.type }}</span><span v-if="isUnsaved(item)" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-100" data-dam-access-unsaved>Unsaved</span></div>
                        <label class="flex items-center gap-2"><input type="checkbox" v-model="item.inherit_children" @change="saved=false">Include subfolders</label>
                        <button type="button" @click="remove(item)" :aria-label="'Remove access for '+item.name" class="text-red-600">Remove</button>
                    </div>
                </fieldset>
                <h3 class="mt-5 text-sm font-semibold">Access from parents and roles</h3>
                <p class="mt-1 text-xs text-gray-500">These grants remain when you remove a direct grant above. Change them on the source folder or in Roles.</p>
                <ul class="mt-2 max-h-48 overflow-y-auto">
                    <li v-for="(source, i) in sources" :key="i" class="border-b py-2 text-sm dark:border-gray-700">
                        <span class="font-medium">@{{ source.name }}</span> · @{{ source.type }}
                        <span class="block text-xs text-gray-500">@{{ source.inherited ? 'Inherited from ' : 'Granted through role on ' }}@{{ source.source_name }}</span>
                    </li>
                </ul>
                <p v-if="!sources.length" class="mt-2 text-sm text-gray-500">No inherited grants.</p>
            </template>
        </div>
        {{-- Sticky, so Save and the unsaved-changes prompt stay in view however long the lists are (UX eval LIB-07). --}}
        <div class="sticky bottom-0 border-t bg-white p-4 dark:border-gray-700 dark:bg-cherry-900">
            <div v-if="confirmingDiscard" role="alertdialog" aria-labelledby="folder-access-discard" class="flex flex-wrap items-center justify-end gap-3">
                <p id="folder-access-discard" class="flex-1 text-sm font-medium">Discard your unsaved access changes?</p>
                <button type="button" class="secondary-button" @click="confirmingDiscard=false" ref="keepEditing">Keep editing</button>
                <button type="button" class="secondary-button !text-red-600" @click="discard">Discard changes</button>
            </div>
            <div v-else class="flex flex-wrap items-center justify-end gap-3">
                <p v-if="dirty" role="status" class="flex-1 text-sm text-amber-700 dark:text-amber-300" data-dam-access-dirty>You have unsaved changes. Save access to apply them.</p>
                <button type="button" class="primary-button" :disabled="!loaded || loading || saving" @click="save" :aria-busy="saving">@{{ saving ? 'Saving…' : 'Save access' }}</button>
            </div>
        </div>
    </dialog>
</script>
<script type="module">
app.component('v-folder-access', {
    template: '#v-folder-access-template',
    data() { return {folder:{}, grants:[], savedGrants:new Map(), sources:[], people:[], users:[], groups:[], search:'', loading:false, loaded:false, saving:false, saved:false, confirmingDiscard:false, error:'', generation:0}; },
    computed: {
        // LIB-07: what differs from the last loaded or saved state, so an added row never looks saved.
        removedCount() { return [...this.savedGrants.keys()].filter(key=>!this.grants.some(g=>this.grantKey(g)===key)).length; },
        dirty() { return this.loaded && (this.removedCount > 0 || this.grants.some(g=>this.isUnsaved(g))); },
        peopleById() { return new Map(this.people.map(person=>[person.id, person])); },
        matches() {
            const term = this.search.trim().toLowerCase();
            return [...this.users.map(x=>({...x,type:'user'})), ...this.groups.map(x=>({...x,type:'group'}))]
                .filter(x=>!this.grants.some(g=>g.type===x.type && g.id===x.id) && (x.name+' '+(x.email||'')).toLowerCase().includes(term)).slice(0,20);
        },
    },
    mounted() { this.$emitter.on('dam:manage-access', this.open); },
    beforeUnmount() { this.generation++; this.$emitter.off('dam:manage-access', this.open); },
    methods: {
        url() { return @json(url(config('app.admin_url').'/dam/directory'))+'/'+this.folder.id+'/access'; },
        async open(folder) {
            if (this.saving) return;
            const generation = ++this.generation;
            this.folder={id:Number(folder.id),name:folder.name}; this.grants=[]; this.sources=[]; this.people=[]; this.users=[]; this.groups=[];
            this.loaded=false; this.loading=true; this.error=''; this.saved=false; this.search=''; this.confirmingDiscard=false; this.savedGrants=new Map();
            if (!this.$refs.dialog.open) this.$refs.dialog.showModal();
            try {
                const {data} = await this.$axios.get(this.url());
                if (generation!==this.generation) return;
                this.grants=[...data.users,...data.groups]; this.remember(); this.sources=data.sources; this.people=data.people || [];
                this.users=data.available_users; this.groups=data.available_groups; this.loaded=true;
            } catch(e) { if (generation===this.generation) this.error='Could not load access. Close and try again.'; }
            finally { if (generation===this.generation) this.loading=false; }
        },
        // Close and Escape ask first when changes are unsaved: closing used to drop them silently (LIB-07).
        close() {
            if (this.saving) return;
            if (this.dirty && !this.confirmingDiscard) { this.confirmingDiscard=true; this.$nextTick(()=>this.$refs.keepEditing?.focus()); return; }
            if (this.confirmingDiscard) { this.confirmingDiscard=false; return; }
            this.discard();
        },
        discard() { this.confirmingDiscard=false; this.generation++; this.$refs.dialog.close(); },
        grantKey(item) { return item.type+':'+item.id; },
        remember() { this.savedGrants=new Map(this.grants.map(g=>[this.grantKey(g), !!g.inherit_children])); },
        isUnsaved(item) { const key=this.grantKey(item); return !this.savedGrants.has(key) || this.savedGrants.get(key)!==!!item.inherit_children; },
        // A user who can already open the folder stays addable (a direct grant survives other changes).
        hasAccessLabel(item) {
            const person = item.type === 'user' ? this.peopleById.get(item.id) : null;
            return person ? @json(trans('dam::app.admin.dam.access.has-access')).replace(':reason', person.reasons[0] || '') : '';
        },
        add(item) { this.grants.push({...item,inherit_children:false}); this.search=''; this.saved=false; },
        remove(item) { this.grants=this.grants.filter(x=>x!==item); this.saved=false; },
        async refreshChoices() {
            const generation=this.generation;
            try { const {data}=await this.$axios.get(this.url()); if(generation!==this.generation) return; this.users=data.available_users; this.groups=data.available_groups; }
            catch(e) { if(generation===this.generation) this.error='Could not refresh the list. Try again.'; }
        },
        async save() {
            if(this.saving || !this.loaded) return;
            this.saving=true; this.error=''; this.saved=false;
            const rows=type=>this.grants.filter(x=>x.type===type).map(x=>({id:x.id,inherit_children:x.inherit_children}));
            const generation=this.generation;
            try {
                await this.$axios.put(this.url(),{users:rows('user'),groups:rows('group')}); this.saved=true;
                if(generation===this.generation) this.remember();
                // Re-read who has access: a grant change moves it, and only the server knows the answer.
                // A failed re-read must not leave the old list looking current next to "Access saved.".
                try { const {data}=await this.$axios.get(this.url()); if(generation===this.generation) { this.people=data.people || []; this.sources=data.sources; } }
                catch(e) { if(generation===this.generation) { this.people=[]; this.error='Access saved, but the list of people with access could not be refreshed. Close and reopen to see it.'; } }
            }
            catch(e) { this.error=e.response?.data?.message || 'Could not save access. Your changes are still here; try again.'; }
            finally { this.saving=false; }
        },
    },
});
</script>
@endPushOnce
