@once('dam-custom-thumbnail-drop')
@push('scripts')
<script type="module">
const customThumbnailDropStates = new WeakMap();
// A card only becomes a thumbnail target after one image file hovers over it this long; until then
// the drop falls through to the folder upload, so a screen full of cards never swallows uploads.
const CUSTOM_THUMBNAIL_ARM_MS = 800;
app.directive('dam-custom-thumbnail-drop', {
    mounted(el, binding) {
        const state = { binding, busy: false, depth: 0, hint: null, handlers: {}, armed: false, armTimer: null };
        customThumbnailDropStates.set(el, state);
        const eligible = () => {
            const asset = state.binding.value;
            return @json((bool) bouncer()->hasPermission('dam.asset.edit'))
                && asset.file_type !== 'image' && !(asset.mime_type || '').toLowerCase().startsWith('image/');
        };
        const external = event => eligible() && Array.from(event.dataTransfer?.types || []).includes('Files');
        // Item kinds and MIME types are readable during a drag. An empty type (a folder, or an unknown
        // file) never arms, so it always reaches the folder upload.
        const singleImage = event => {
            const items = Array.from(event.dataTransfer?.items || []);
            return items.length === 1 && items[0].kind === 'file' && ['image/jpeg', 'image/png', 'image/gif'].includes(items[0].type);
        };
        const clearHint = () => { state.hint?.remove(); state.hint = null; };
        const disarm = () => { clearTimeout(state.armTimer); state.armTimer = null; state.armed = false; };
        const hint = text => {
            if (!state.hint) {
                state.hint = document.createElement('div');
                state.hint.setAttribute('role', 'status');
                state.hint.style.cssText = 'position:absolute;inset:0;z-index:30;display:flex;align-items:center;justify-content:center;background:rgba(30,41,59,.93);color:white;border:2px solid #818cf8;border-radius:6px;padding:8px;text-align:center;font-size:13px;pointer-events:none';
                el.appendChild(state.hint);
            }
            state.hint.textContent = text;
        };
        const flash = (type, message) => state.binding.instance.$emitter.emit('add-flash', { type, message });
        state.handlers.dragenter = event => {
            if (!external(event) || !singleImage(event)) return;
            state.depth++;
            if (state.depth !== 1 || state.armed || state.armTimer) return;
            state.armTimer = setTimeout(() => {
                state.armTimer = null;
                if (!state.depth) return;
                state.armed = true;
                hint(state.busy ? @json(trans('dam::app.admin.explorer.custom-thumbnail.saving')) : @json(trans('dam::app.admin.explorer.custom-thumbnail.drop')));
            }, CUSTOM_THUMBNAIL_ARM_MS);
        };
        state.handlers.dragover = event => {
            if (!state.armed || !external(event)) return;
            event.preventDefault();
            // Tells the upload wrapper to hide its overlay while this card is the target.
            event.damCustomThumbnailArmed = true;
            event.dataTransfer.dropEffect = state.busy ? 'none' : 'copy';
        };
        state.handlers.dragleave = event => {
            if (!external(event) || !state.depth) return;
            state.depth = Math.max(0, state.depth - 1);
            if (state.depth) return;
            disarm();
            if (!state.busy) clearHint();
        };
        state.handlers.drop = async event => {
            if (!external(event)) return;
            const armed = state.armed;
            disarm();
            if (!armed) {
                // Not an intentional thumbnail drop: leave it to the folder upload.
                state.depth = 0;
                if (!state.busy) clearHint();
                return;
            }
            event.preventDefault();
            // Let the upload wrapper clear its overlay, but never import this image as another asset.
            event.damCustomThumbnailHandled = true;
            state.depth = 0;
            if (state.busy) return;
            const files = Array.from(event.dataTransfer.files || []);
            if (files.length !== 1 || !/\.(jpe?g|png|gif)$/i.test(files[0].name)
                || (files[0].type && !['image/jpeg', 'image/png', 'image/gif'].includes(files[0].type))) {
                clearHint();
                flash('error', @json(trans('dam::app.admin.explorer.custom-thumbnail.invalid')));
                return;
            }
            if (files[0].size > 32 * 1024 * 1024) {
                clearHint();
                flash('error', @json(trans('dam::app.admin.explorer.custom-thumbnail.too-large')));
                return;
            }
            state.busy = true;
            el.setAttribute('aria-busy', 'true');
            hint(@json(trans('dam::app.admin.explorer.custom-thumbnail.saving')));
            const form = new FormData();
            form.append('thumbnail', files[0]);
            const asset = state.binding.value;
            try {
                const url = @json(route('admin.dam.assets.custom_thumbnail.store', ['id' => '__ASSET__'])).replace('__ASSET__', asset.id);
                const response = await state.binding.instance.$axios.post(url, form);
                asset.path = response.data.thumbnail_url;
                asset.has_custom_thumbnail = true;
                flash('success', @json(trans('dam::app.admin.explorer.custom-thumbnail.saved')));
                state.binding.instance.$emit('refresh');
            } catch (error) {
                flash('error', error.response?.data?.errors?.thumbnail?.[0] || error.response?.data?.message
                    || @json(trans('dam::app.admin.explorer.custom-thumbnail.failed')));
            } finally {
                state.busy = false;
                el.removeAttribute('aria-busy');
                clearHint();
            }
        };
        for (const [name, handler] of Object.entries(state.handlers)) el.addEventListener(name, handler);
        if (eligible()) el.setAttribute('data-dam-custom-thumbnail', '');
    },
    updated(el, binding) {
        const state = customThumbnailDropStates.get(el);
        if (state) state.binding = binding;
    },
    unmounted(el) {
        const state = customThumbnailDropStates.get(el);
        if (!state) return;
        for (const [name, handler] of Object.entries(state.handlers)) el.removeEventListener(name, handler);
        clearTimeout(state.armTimer);
        state.hint?.remove();
        customThumbnailDropStates.delete(el);
    },
});
</script>
@endpush
@endonce
