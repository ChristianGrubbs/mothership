{{-- Report switcher and range picker shared by every staff report (P1a security, P1b analytics). Plain links: each
     choice is a GET. A report shows here only to someone whose role carries its key. --}}
@php
    $reportLinks = array_filter([
        'login-access'       => ['dam.reports.security', 'dam::app.admin.reports.login-access.title'],
        'operational-health' => ['dam.reports.security', 'dam::app.admin.reports.health.title'],
        'engagement'         => ['dam.reports.view', 'dam::app.admin.analytics.engagement.title'],
        'friction'           => ['dam.reports.view', 'dam::app.admin.analytics.friction.title'],
        'adoption'           => ['dam.reports.view', 'dam::app.admin.analytics.adoption.title'],
        'journeys'           => ['dam.reports.journeys', 'dam::app.admin.analytics.journeys.title'],
    ], fn (array $link) => bouncer()->hasPermission($link[0]));
@endphp

<div class="mt-4 flex flex-wrap items-center justify-between gap-2.5">
    <nav class="flex flex-wrap items-center gap-2.5" aria-label="@lang('dam::app.admin.reports.menu')">
        @foreach ($reportLinks as $report => [$permission, $label])
            <a
                href="{{ route('admin.dam.reports.'.$report, ['days' => $days]) }}"
                class="{{ $report === $current ? 'primary-button' : 'secondary-button' }}"
                @if ($report === $current) aria-current="page" @endif
            >
                @lang($label)
            </a>
        @endforeach
    </nav>

    <div class="flex flex-wrap items-center gap-2.5">
        @foreach ($ranges as $range)
            <a
                href="{{ route('admin.dam.reports.'.$current, ['days' => $range] + ($rangeQuery ?? [])) }}"
                class="{{ $range === $days ? 'primary-button' : 'secondary-button' }}"
                @if ($range === $days) aria-current="true" @endif
            >
                @lang('dam::app.admin.reports.range', ['days' => $range])
            </a>
        @endforeach
    </div>
</div>
