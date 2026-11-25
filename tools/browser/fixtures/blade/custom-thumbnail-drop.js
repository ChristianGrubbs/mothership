
const customThumbnailDropStates = new WeakMap();
const thumbnailMimeTypes = new Set(['image/jpeg', 'image/png', 'image/gif']);
const unknownThumbnailMime = type => !type || type === 'application/octet-stream';
app.directive('dam-custom-thumbnail-drop', {
    mounted(el, binding) {
        const state = { binding, busy: false, hint: null, handlers: {} };
        customThumbnailDropStates.set(el, state);
        const eligible = () => {
            const asset = state.binding.value;
            return true                && asset.file_type !== 'image' && !(asset.mime_type || '').toLowerCase().startsWith('image/');
        };
        const external = event => eligible() && Array.from(event.dataTransfer?.types || []).includes('Files');
        const fileItems = transfer => Array.from(transfer.items || []).filter(item => item.kind === 'file');
        const clearHint = () => {
            window.removeEventListener('pointermove', clearDragHint);
            state.hint?.remove();
            state.hint = null;
        };
        const clearDragHint = () => { if (!state.busy) clearHint(); };
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
        state.handlers.dragover = event => {
            if (!external(event)) return;
            // Some browsers do not expose the file type or items until drop.
            const items = fileItems(event.dataTransfer);
            if (items.length > 1 || (items[0] && !thumbnailMimeTypes.has(items[0].type) && !unknownThumbnailMime(items[0].type))) return;
            event.preventDefault();
            // Tells the upload wrapper to hide its overlay while this card is the target.
            event.damCustomThumbnailTargeted = true;
            event.dataTransfer.dropEffect = state.busy ? 'none' : 'copy';
            if (!state.busy) {
                hint("Drop image to set thumbnail");
                window.addEventListener('pointermove', clearDragHint, { once: true });
            }
        };
        state.handlers.dragleave = event => {
            if (!(event.relatedTarget instanceof Node) || !el.contains(event.relatedTarget)) clearDragHint();
        };
        state.handlers.drop = async event => {
            if (!external(event)) return;
            const files = event.dataTransfer.files;
            const file = files?.length === 1 ? files[0] : null;
            const item = fileItems(event.dataTransfer)[0];
            // Directories, other file types and batches still belong to the folder uploader.
            if (!file || item?.webkitGetAsEntry?.()?.isDirectory
                || !(thumbnailMimeTypes.has(file.type) || (unknownThumbnailMime(file.type) && /\.(jpe?g|png|gif)$/i.test(file.name)))) {
                clearDragHint();
                return;
            }
            event.preventDefault();
            // Let the upload wrapper clear its overlay without importing this image as another asset.
            event.damCustomThumbnailHandled = true;
            if (state.busy) return;
            if (file.size > 32 * 1024 * 1024) {
                clearHint();
                flash('error', "Choose an image smaller than 32 MB.");
                return;
            }
            state.busy = true;
            el.setAttribute('aria-busy', 'true');
            hint("Saving thumbnail\u2026");
            const form = new FormData();
            form.append('thumbnail', file);
            const { value: asset, instance } = state.binding;
            try {
                const endpoint = 'http:\/\/mothership.test\/admin\/dam\/assets\/__ASSET__\/custom-thumbnail'.replace('__ASSET__', asset.id);
                const { data: { thumbnail_url: url } } = await instance.$axios.post(endpoint, form);
                asset.path = url;
                asset.has_custom_thumbnail = true;
                instance.$emitter.emit('dam:thumbnail-updated', { id: asset.id, url });
                flash('success', "Custom thumbnail saved.");
            } catch (error) {
                flash('error', error.response?.data?.errors?.thumbnail?.[0] || error.response?.data?.message
                    || "Could not save the thumbnail. Try again.");
            } finally {
                state.busy = false;
                el.removeAttribute('aria-busy');
                clearHint();
            }
        };
        state.clearHint = clearHint;
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
        state.clearHint();
        customThumbnailDropStates.delete(el);
    },
});
