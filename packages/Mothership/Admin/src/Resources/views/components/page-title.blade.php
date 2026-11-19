@props([
    'title'      => null,
    'subtitle'   => '',
    'size'       => 'xl',
    'breadcrumb' => true,
    'parents'    => [],
    // A page that lives in a rail group ('away', 'sorting') shows that group's name here, not a breadcrumb: the
    // group is not a page, so there is nothing to link to.
    'group'      => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-1.5']) }}>
    @if ($group)
        <p class="bridge-page-group" data-page-group="{{ $group }}">@lang('admin::app.components.layouts.rail.'.$group)</p>
    @elseif ($breadcrumb)
        <x-admin::breadcrumbs :leaf="$title" :parents="$parents" />
    @endif

    <x-admin::heading :title="$title" :subtitle="$subtitle" as="h1" :size="$size" />

    {{ $slot }}
</div>
