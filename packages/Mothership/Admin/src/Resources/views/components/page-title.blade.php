@props([
    'title'      => null,
    'subtitle'   => '',
    'size'       => 'xl',
    'breadcrumb' => true,
    'parents'    => [],
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-1.5']) }}>
    @if ($breadcrumb)
        <x-admin::breadcrumbs :leaf="$title" :parents="$parents" />
    @endif

    <x-admin::heading :title="$title" :subtitle="$subtitle" as="h1" :size="$size" />

    {{ $slot }}
</div>
