@once('mothership-favorites-client')
<script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/asset-favorites.js')) !!}</script>
<script type="module">
window.MothershipFavorites ||= window.MothershipFavoriteState.client({
    request: async ({method, ids, id}) => {
        const base = @js(url(config('app.admin_url').'/home/favorites'));
        let url = base + (method === 'GET' ? '/status' : '/' + id);
        if (method === 'GET') {
            const query = new URLSearchParams();
            ids.forEach(assetId => query.append('ids[]', assetId));
            url += '?' + query;
        }
        const response = await fetch(url, {
            method,
            headers: {Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Favorite could not be updated.');
        return data;
    },
});
</script>
@endonce
