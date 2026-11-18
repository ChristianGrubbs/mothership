{{-- Regression fixture for commit 1096e385: Blade compiles `@error` as its validation directive. --}}
<div id="files-table">
    <img v-if="file.thumbnail_url && ! thumbBroken[file.id]" :src="file.thumbnail_url" @error="thumbBroken[file.id] = true">
</div>
