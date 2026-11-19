@props([
    'title',
    'description' => null,
    'breadcrumb'  => true,
    'parents'     => [],
    'group'       => null,
])

<div {{ $attributes->merge(['class' => 'bridge-page-head flex min-h-10 items-center justify-between gap-4 max-sm:flex-wrap']) }}>
    <x-admin::page-title :title="$title" :subtitle="$description" :breadcrumb="$breadcrumb" :parents="$parents" :group="$group">
        {{ $content ?? '' }}
    </x-admin::page-title>

    @isset($actions)
        <div class="flex items-center gap-2.5">
            {{ $actions }}
        </div>
    @endisset
</div>
