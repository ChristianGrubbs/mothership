@once
{{-- The format menu is anchored to the button's LEFT edge (left-0): Convert is the first control of the
     toolbar cluster and the menu is wider than the button, so a right-anchored menu ran off the panel's
     left edge and was clipped (operator screenshot 2026-09-15). Keep this note outside the x-template:
     the browser test mounts the raw template text. --}}
<script type="text/x-template" id="v-dam-asset-conversion-template">
    <div class="contents">
        <div class="relative" @keydown.esc.stop="open = false" @focusout="closeOutside($event)">
            <button type="button" data-dam-vectorize class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800" :disabled="busy || ready !== true" :aria-expanded="open" @click="open = !open">Convert ▾</button>
            <div v-if="open" class="absolute left-0 top-full z-40 mt-1 w-48 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-2 shadow-lg" data-dam-conversion-menu>
                <p class="px-2 py-1 text-xs text-gray-500">Save to this asset’s Files</p>
                <button v-for="format in ['svg', 'pdf']" :key="format" type="button" class="block w-full rounded px-2 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-transparent dark:disabled:text-gray-500" :disabled="exhausted" @click="convert(format)">@{{ format.toUpperCase() }}</button>
                <p v-if="exhausted" class="px-2 py-1 text-xs text-gray-600 dark:text-gray-300" data-dam-conversion-exhausted>No conversions left. An admin can add more.</p>
                <p v-else-if="allowance && !allowance.unlimited" class="px-2 py-1 text-xs text-gray-500">@{{ allowance.remaining }} @{{ allowance.remaining === 1 ? 'conversion' : 'conversions' }} available</p>
            </div>
        </div>
        <span v-if="result?.status === 'completed'" class="text-xs text-green-700 dark:text-green-400" role="status"><a :href="filesUrl" class="underline">@{{ result.format?.toUpperCase() }} saved to Files</a></span>
        <span v-if="ready === false && ! error" class="text-xs text-gray-500">Conversion unavailable</span>
        <span v-if="error" class="text-xs text-red-600" role="alert">@{{ error }}</span>
        <div v-if="busy" class="absolute inset-0 z-30 flex flex-col items-center justify-center gap-3 rounded-lg bg-white/75 dark:bg-gray-900/80" role="status" aria-live="polite" aria-busy="true" data-dam-conversion-progress>
            <svg class="h-9 w-9 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".2"/><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <p class="text-sm font-semibold">@{{ result?.status === 'queued' ? 'Queued for conversion…' : 'Converting image…' }}</p>
            <p class="text-xs text-gray-600 dark:text-gray-300">The result will be saved to Files. You can leave this page.</p>
        </div>
    </div>
</script>
<script type="module">
app.component('v-dam-asset-conversion', {
    template: '#v-dam-asset-conversion-template',
    props: {assetId: {type: Number, required: true}, extension: String, stateUrl: String, storeUrl: String, filesUrl: String},
    data() { return {open: false, submitting: false, result: null, ready: null, allowance: null, error: '', timer: null, alive: true, retries: 0, requestId: null, requestFormat: null}; },
    computed: {
        busy() { return this.submitting || ['queued', 'processing'].includes(this.result?.status); },
        // A metered account with nothing left: the server would only answer 403, so the formats are disabled
        // and the menu says why instead of "0 conversions available" (UI review 2026-10-06).
        exhausted() { return !!this.allowance && !this.allowance.unlimited && (this.allowance.remaining ?? 0) < 1; },
    },
    mounted() { this.refresh(); },
    beforeUnmount() { this.alive=false; clearTimeout(this.timer); },
    methods: {
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
        schedulePoll(delay = 1500) { clearTimeout(this.timer); if(this.alive) this.timer=setTimeout(()=>this.refresh(),delay); },
        async refresh() {
            try {
                const {data}=await this.$axios.get(this.stateUrl, {params: this.result?.id ? {result_id:this.result.id} : {}});
                if(!this.alive) return;
                const previous=this.result?.status;
                this.result=data.result; this.ready=data.ready; this.allowance=data.allowance; this.retries=0;
                this.error=this.result?.status === 'failed' ? this.result.message : '';
                if(this.result?.status === 'completed' && previous !== 'completed') this.$emitter.emit('dam-asset-files-updated',{assetId:this.assetId});
                if(this.busy) this.schedulePoll();
            } catch(error) {
                if(!this.alive) return;
                // A failed status check is not a failed conversion: say what actually went wrong (FAULT-19).
                this.error=['Couldn’t check the conversion status.', globalThis.mothership?.requestErrorMessage?.(error)].filter(Boolean).join(' ');
                if(![401,403,404,419].includes(error.response?.status)) this.schedulePoll(Math.min(15000,1500 * ++this.retries));
                else {this.result=null; this.ready=false;}
            }
        },
        async convert(format) {
            if(this.busy || this.exhausted || !['svg','pdf'].includes(format)) return;
            this.submitting=true; this.open=false; this.error='';
            try {
                if(!this.requestId || this.requestFormat !== format) {this.requestId=this.newRequestId(); this.requestFormat=format;}
                const {data}=await this.$axios.post(this.storeUrl,{request_id:this.requestId,format});
                if(!this.alive) return;
                this.result=data.result; this.requestId=null;
                this.schedulePoll();
            } catch(error) {
                if(!this.alive) return;
                this.error=globalThis.mothership?.requestErrorMessage?.(error) || 'Could not start conversion. Try again.';
                if(error.response && error.response.status < 500) this.requestId=null;
            } finally {if(this.alive) this.submitting=false;}
        },
    },
});
</script>
@endonce
