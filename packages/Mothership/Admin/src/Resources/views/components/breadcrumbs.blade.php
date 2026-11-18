@props([
    'leaf'    => null,
    // Explicit crumbs ([['label' => ..., 'url' => ...]]) for a page whose menu item has no parent.
    'parents' => [],
    // A page the menu cannot place (it sits under Library by URL) names its own full trail.
    'ownTrail' => false,
])

@php
    $trail = ! $ownTrail && isset($menu) ? $menu->getActiveTrail() : [];

    $currentUrl = trim(Request::url(), '/');

    $deepest = end($trail) ?: null;

    $leafIsCurrent = $deepest && trim($deepest['url'], '/') === $currentUrl;

    $ancestors = $leafIsCurrent ? array_slice($trail, 0, -1) : $trail;

    $leafLabel = $leafIsCurrent && $deepest ? trans($deepest['name']) : $leaf;

    // A page named like its parent (Library configuration under Library) must not read "LIBRARY / LIBRARY".
    if ($leafIsCurrent) {
        $ancestors = array_values(array_filter($ancestors, fn ($crumb) => trans($crumb['name']) !== $leafLabel));
    }

    $explicitCrumbs = empty($ancestors) ? $parents : [];
@endphp

@if (! empty($ancestors) || ! empty($explicitCrumbs))
    <nav
        aria-label="{{ trans('admin::app.components.layouts.breadcrumbs.label') }}"
        class="flex flex-wrap items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"
    >
        @foreach ($ancestors as $crumb)
            <a
                href="{{ $crumb['url'] }}"
                class="text-gray-600 hover:text-mothership-primary hover:text-primary-700 dark:text-gray-300 dark:hover:text-primary-400"
            >
                @lang($crumb['name'])
            </a>

            <span class="text-gray-300 dark:text-gray-600">/</span>
        @endforeach

        @foreach ($explicitCrumbs as $crumb)
            <a
                href="{{ $crumb['url'] }}"
                class="text-gray-600 hover:text-mothership-primary hover:text-primary-700 dark:text-gray-300 dark:hover:text-primary-400"
            >
                {{ $crumb['label'] }}
            </a>

            <span class="text-gray-300 dark:text-gray-600">/</span>
        @endforeach

        @if ($leafLabel)
            <span class="text-gray-400 dark:text-gray-500">
                {{ $leafLabel }}
            </span>
        @endif
    </nav>
@endif
