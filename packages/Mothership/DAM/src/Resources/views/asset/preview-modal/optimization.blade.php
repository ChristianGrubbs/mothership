@once
{{-- Modelled on conversion.blade.php: the menu is anchored to the button's LEFT edge for the same
     clipping reason (operator screenshot 2026-09-15). Kept outside the x-template because the
     browser test mounts the raw template text. The busy overlay's inner plate (data-dam-optimize-plate) is
     the same backing as the 3D preview prompt in card.blade.php, so the status stays readable over a busy image. --}}
<script type="text/x-template" id="v-dam-asset-optimization-template">
    <div class="contents">
        <div class="relative" @keydown.esc.stop="open = false" @focusout="closeOutside($event)">
            <button type="button" ref="trigger" data-dam-optimize class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800" :disabled="busy || ready !== true" :aria-expanded="open" @click="open = !open">Optimize ▾</button>
            <div v-if="open" class="absolute left-0 top-full z-40 mt-1 w-56 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-3 shadow-lg" data-dam-optimize-menu>
                <template v-if="fileType === 'image'">
                    <p class="mb-1 text-xs font-semibold text-gray-600 dark:text-gray-300">Size</p>
                    <label v-for="choice in [['1','Original'],['0.75','75%'],['0.5','50%']]" :key="choice[0]" class="flex items-center gap-2 py-1 text-sm">
                        <input type="radio" name="dam-optimize-scale" :value="choice[0]" v-model="form.scale" data-dam-optimize-scale>@{{ choice[1] }}
                    </label>
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.adaptive" data-dam-optimize-adaptive>Allow format change (smaller file)
                    </label>
                </template>
                <template v-else-if="fileType === 'video'">
                    <p class="mb-1 text-xs font-semibold text-gray-600 dark:text-gray-300">Compression</p>
                    <label v-for="choice in [['25','Gentle'],['50','Balanced'],['75','Strong']]" :key="choice[0]" class="flex items-center gap-2 py-1 text-sm">
                        <input type="radio" name="dam-optimize-compression" :value="choice[0]" v-model="form.compression" data-dam-optimize-compression>@{{ choice[1] }}
                    </label>
                    <p class="mb-1 mt-2 text-xs font-semibold text-gray-600 dark:text-gray-300">Encoder</p>
                    <label v-for="choice in [['hardware','Faster'],['software','Smaller file']]" :key="choice[0]" class="flex items-center gap-2 py-1 text-sm">
                        <input type="radio" name="dam-optimize-encoder" :value="choice[0]" v-model="form.encoder" data-dam-optimize-encoder>@{{ choice[1] }}
                    </label>
                    <p class="mb-1 mt-2 text-xs font-semibold text-gray-600 dark:text-gray-300">Size</p>
                    <label v-for="choice in [['1','Original'],['0.75','75%'],['0.5','50%']]" :key="choice[0]" class="flex items-center gap-2 py-1 text-sm">
                        <input type="radio" name="dam-optimize-video-scale" :value="choice[0]" v-model="form.scale" data-dam-optimize-scale>@{{ choice[1] }}
                    </label>
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.removeAudio" data-dam-optimize-remove-audio>Remove audio
                    </label>
                </template>
                <p v-else class="mb-2 text-xs text-gray-500">PDFs optimize with no extra settings.</p>
                <button type="button" data-dam-optimize-run class="mt-3 w-full rounded px-2 py-2 text-left text-sm font-semibold text-primary-700 hover:bg-gray-100 dark:hover:bg-gray-700" @click="optimize()">Optimize</button>
            </div>
        </div>
        <span v-if="result?.status === 'completed' && result.outcome === 'optimized'" class="text-xs text-green-700 dark:text-green-400" role="status"><a :href="filesUrl" class="underline">@{{ formatBytes(result.old_bytes) }} → @{{ formatBytes(result.new_bytes) }} (−@{{ savedPercent }}%) @{{ (result.output_extension || '').toUpperCase() }} saved to Files</a></span>
        <span v-if="result?.status === 'completed' && result.outcome === 'no_reduction'" class="text-xs text-gray-500" role="status">Already optimized, no smaller copy made</span>
        <span v-if="ready === false && ! error" class="text-xs text-gray-500" data-dam-optimize-unavailable>Optimization unavailable. It isn't set up on this server; ask an admin.</span>
        <span v-if="error" class="text-xs text-red-600" role="alert">@{{ error }}<button v-if="failureId" type="button" class="ml-2 underline" data-dam-optimize-dismiss @click="dismiss()">Dismiss ×</button></span>
        <div v-if="busy" class="absolute inset-0 z-30 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/75 dark:bg-gray-900/80" role="status" aria-live="polite" aria-busy="true" data-dam-optimize-progress>
            <div class="flex max-w-xs flex-col items-center gap-3 rounded-xl bg-white/90 px-6 py-4 shadow dark:bg-gray-900/90" data-dam-optimize-plate>
                <svg class="h-9 w-9 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".2"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <p class="text-sm font-semibold">@{{ result?.status === 'queued' ? 'Queued for optimization…' : 'Optimizing…' }}</p>
                <p v-if="result?.status === 'queued' && result.message" class="text-center text-xs font-semibold text-amber-700 dark:text-amber-400" data-dam-optimize-stalled>@{{ result.message }}</p>
                <p class="text-center text-xs text-gray-600 dark:text-gray-300">The result will be saved to Files. You can leave this page.</p>
            </div>
        </div>
    </div>
</script>
<script type="module">
// Failures dismissed during this page session, shared by every instance of the component (a remount, or another
// asset's panel) so a blocked or full localStorage cannot bring a dismissed failure back. Only non-empty string ids,
// bounded to the same 50 as the stored list, oldest dropped first.
const dismissedThisSession = new Set();
const rememberDismissed = (id) => {
    if(typeof id !== 'string' || id === '') return;
    dismissedThisSession.delete(id); dismissedThisSession.add(id);
    while(dismissedThisSession.size > 50) dismissedThisSession.delete(dismissedThisSession.values().next().value);
};
app.component('v-dam-asset-optimization', {
    template: '#v-dam-asset-optimization-template',
    props: {assetId: {type: Number, required: true}, extension: String, stateUrl: String, storeUrl: String, filesUrl: String},
    data() {
        return {
            open: false, submitting: false, result: null, ready: null, error: '', timer: null, dueAt: 0, alive: true, retries: 0, requestId: null, failureId: null, wake: null, inflight: false, lastStart: 0, seq: 0,
            form: {scale: '1', adaptive: false, compression: '50', encoder: 'hardware', removeAudio: false},
        };
    },
    computed: {
        busy() { return this.submitting || ['queued', 'processing'].includes(this.result?.status); },
        fileType() {
            const ext = (this.extension || '').toLowerCase();
            if (['jpg', 'jpeg', 'png'].includes(ext)) return 'image';
            if (ext === 'mp4') return 'video';
            if (ext === 'pdf') return 'pdf';
            return null;
        },
        savedPercent() {
            if (!this.result?.old_bytes) return 0;
            return Math.round((1 - (this.result.new_bytes / this.result.old_bytes)) * 100);
        },
    },
    mounted() {
        this.refresh();
        // A hidden tab throttles timers (to once a minute after a few minutes), so a result that landed while
        // the tab was in the background would wait for the next throttled tick. Check the moment it is visible
        // again. The wake only fires while a run is in flight and nothing is already being fetched, is coalesced
        // into at most one pending poll and never starts one sooner than 1.5 s after the last request began, so it
        // adds no polling when idle and never polls faster than the base rate.
        this.wake = () => {
            if (this.busy && !this.inflight && !globalThis.document?.hidden) this.schedulePoll(0, true);
        };
        globalThis.document?.addEventListener?.('visibilitychange', this.wake);
        globalThis.addEventListener?.('focus', this.wake);
        globalThis.addEventListener?.('online', this.wake);
    },
    beforeUnmount() {
        this.alive = false; clearTimeout(this.timer);
        globalThis.document?.removeEventListener?.('visibilitychange', this.wake);
        globalThis.removeEventListener?.('focus', this.wake);
        globalThis.removeEventListener?.('online', this.wake);
    },
    methods: {
        formatBytes(bytes) {
            if (!bytes && bytes !== 0) return '';
            const units = ['B', 'KB', 'MB', 'GB'];
            let value = bytes, index = 0;
            while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
            return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
        },
        newRequestId() {
            if(globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
            const bytes=new Uint8Array(16);
            if(globalThis.crypto?.getRandomValues) globalThis.crypto.getRandomValues(bytes);
            else for(let i=0;i<bytes.length;i++) bytes[i]=Math.floor(Math.random()*256);
            bytes[6]=(bytes[6]&15)|64; bytes[8]=(bytes[8]&63)|128;
            const hex=Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');
            return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
        },
        closeOutside(event) { if (!event.currentTarget.contains(event.relatedTarget)) this.open=false; },
        // At most one poll is ever pending, and none starts sooner than 1.5 s after the previous request began (the
        // base rate), whatever asked for it. A wake request (`wake`) never postpones a poll that is already due sooner.
        schedulePoll(delay = 1500, wake = false) {
            if(!this.alive) return;
            const due=Math.max(Date.now()+delay,this.lastStart+1500);
            if(wake && this.timer && this.dueAt <= due) return;
            clearTimeout(this.timer); this.dueAt=due;
            this.timer=setTimeout(()=>{this.timer=null; this.refresh();},Math.max(0,due-Date.now()));
        },
        optionsPayload() {
            if (this.fileType === 'image') return {scale: this.form.scale, adaptive: this.form.adaptive ? '1' : '0'};
            if (this.fileType === 'video') return {compression: this.form.compression, encoder: this.form.encoder, scale: this.form.scale, remove_audio: this.form.removeAudio ? '1' : '0'};
            return {};
        },
        // Ids of failures the user has dismissed, from storage. Whatever is stored is untrusted: only an array's
        // non-empty strings count, so `{}`, null, a number or a bare string can neither throw nor match by substring.
        readDismissed() {
            try {
                const stored=JSON.parse(globalThis.localStorage?.getItem('dam-optimize-dismissed') || '[]');
                return Array.isArray(stored) ? stored.filter(id => typeof id === 'string' && id !== '') : [];
            } catch(error) { return []; }
        },
        // A failed or expired result shows inline until the user dismisses it (F-08, 2026-10-12): the status endpoint
        // keeps returning the newest row, so an old failure used to come back on every visit. A dismissed id never
        // shows again in this browser; a failure the server marks stale (settled more than 7 days ago) no longer shows; a new id always does.
        failureText(result) {
            if(result?.status !== 'failed' && result?.status !== 'expired') return '';
            const id=typeof result.id === 'string' ? result.id : '';
            if(id && (dismissedThisSession.has(id) || this.readDismissed().includes(id))) return '';
            // The server decides a failure is too old to show (stale), never this browser's clock.
            if(result.stale === true) return '';
            return result.message || '';
        },
        // The Dismiss button disappears with its message, which would drop keyboard focus to <body>: put it on the
        // Optimize trigger, or on the nearest enabled toolbar control when the trigger is disabled or gone.
        focusAfterDismiss() {
            const focusIt = () => {
                const trigger=this.$refs?.trigger;
                if(trigger && !trigger.disabled && trigger.isConnected) { trigger.focus(); return; }
                const toolbar=this.$el?.parentElement;
                const next=toolbar && Array.from(toolbar.querySelectorAll('button, a[href], [tabindex]')).find(el => !el.disabled && el.tabIndex >= 0 && !this.$el.contains(el));
                next?.focus();
            };
            if(this.$nextTick) this.$nextTick(focusIt); else Promise.resolve().then(focusIt);
        },
        dismiss() {
            const id=this.failureId;
            this.error=''; this.failureId=null;
            if(!id) return;
            this.focusAfterDismiss();
            rememberDismissed(id);
            try { globalThis.localStorage?.setItem('dam-optimize-dismissed', JSON.stringify(Array.from(new Set(this.readDismissed().concat(id))).slice(-50))); } catch(error) { /* blocked or full storage: the session set above still holds it */ }
        },
        // The browser's own wording for a failed request; a formatter that throws must never stop the caller.
        describeError(error) {
            try { return globalThis.mothership?.requestErrorMessage?.(error) || ''; } catch(failure) { return ''; }
        },
        // One refresh in flight at a time; every response is checked against a sequence number so an answer that
        // was overtaken (a newer result arrived while it was on the wire) never overwrites the newer state.
        async refresh() {
            if(this.inflight) return;
            this.inflight=true; this.lastStart=Date.now(); const seq=++this.seq;
            try {
                // A poll that never answers must not freeze the chain: time it out and let the retry below run.
                const {data}=await this.$axios.get(this.stateUrl,{timeout:4000});
                if(!this.alive) return;
                if(seq !== this.seq) { if(this.busy) this.schedulePoll(); return; }
                const previous=this.result?.status;
                this.result=data.result; this.ready=data.ready; this.retries=0;
                this.error=this.failureText(this.result);
                this.failureId=this.error ? this.result.id : null;
                if(this.result?.status === 'completed' && previous !== 'completed') this.$emitter.emit('dam-asset-files-updated',{assetId:this.assetId});
                if(this.busy) this.schedulePoll();
            } catch(error) {
                if(!this.alive) return;
                if(seq !== this.seq) { if(this.busy) this.schedulePoll(); return; }
                // A failed status check is not a failed optimization: say what actually went wrong (FAULT-19).
                this.error=['Couldn’t check the optimization status.', this.describeError(error)].filter(Boolean).join(' ');
                this.failureId=null;
                // While a run is in flight its result is awaited, so keep retrying at the normal 1.5 s rate (never faster);
                // only an idle component backs off, up to 15 s. Whatever went wrong above, the next poll is always scheduled.
                if(![401,403,404,419].includes(error?.response?.status)) this.schedulePoll(this.busy ? 1500 : Math.min(15000,1500 * ++this.retries));
                else {this.result=null; this.ready=false;}
            } finally { this.inflight=false; }
        },
        async optimize() {
            if(this.busy) return;
            this.submitting=true; this.open=false; this.error=''; this.failureId=null;
            try {
                if(!this.requestId) this.requestId=this.newRequestId();
                const {data}=await this.$axios.post(this.storeUrl,Object.assign({request_id:this.requestId},this.optionsPayload()));
                if(!this.alive) return;
                this.result=data.result; this.requestId=null; this.seq++;
                this.schedulePoll();
            } catch(error) {
                if(!this.alive) return;
                this.error=this.describeError(error) || 'Could not start optimization. Try again.'; this.failureId=null;
                if(error.response && error.response.status < 500) this.requestId=null;
            } finally {if(this.alive) this.submitting=false;}
        },
    },
});
</script>
@endonce
