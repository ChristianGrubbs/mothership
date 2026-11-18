@php($heading = ! empty($member) && ! empty($memberName) ? $memberName : ($asset->title ?: $asset->file_name))
<x-admin::layouts>
    <x-slot:title>{{ $heading }}</x-slot:title>
    <div class="flex items-center justify-between py-2">
        <p class="text-xl font-bold text-gray-800 dark:text-white" data-web-page-heading>{{ $heading }}</p>
        <a href="{{ route('admin.dam.assets.index') }}" class="secondary-button">@lang('dam::app.admin.dam.web-pages.back-to-library')</a>
    </div>
    <div style="height: calc(100vh - 160px);">
        @if (! empty($member))
            {{-- The pin from the link: this one file of the stack, or an explicit stale/unavailable state, never another file. --}}
            <v-dam-web-page-frame :asset-id="{{ (int) $asset->id }}" file-name="{{ e($heading) }}" :file-id="{{ (int) $member['file'] }}" checksum="{{ $member['checksum'] }}"></v-dam-web-page-frame>
        @else
            <v-dam-web-page-frame :asset-id="{{ (int) $asset->id }}" file-name="{{ e($asset->file_name) }}"></v-dam-web-page-frame>
        @endif
    </div>
    @include('dam::asset.preview-modal.web.frame-script')
</x-admin::layouts>
