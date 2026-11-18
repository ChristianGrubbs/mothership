{{-- The fixed form from commit 1096e385, plus an escaped directive and a Blade comment that must not trip the lint. --}}
{{-- <img @error="ignored in a comment"> --}}
<div id="files-table">
    <img v-if="file.thumbnail_url && ! thumbBroken[file.id]" :src="file.thumbnail_url" v-on:error="thumbBroken[file.id] = true" @load="loaded = true">
    <x-admin::layouts.header :parents="[['label' => trans('dam::app.title'), 'url' => route('x')]]" />
    <span @@error="literal">@{{ file.name }}</span>
</div>
