<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.analytics.journey.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.analytics.journey.title')"
        :description="trans('dam::app.admin.analytics.journey.description', ['actor' => $actor])"
    />

    @include('dam::reports.nav', ['current' => $current, 'days' => $days, 'ranges' => $ranges])

    <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
        <a href="{{ route('admin.dam.reports.journeys', ['days' => $days]) }}" class="text-violet-700 dark:text-violet-300 hover:underline">@lang('dam::app.admin.analytics.journey.back')</a>
        @if ($gaps > 0)
            <span class="ml-2">@lang('dam::app.admin.analytics.journey.gaps', ['count' => $gaps])</span>
        @endif
    </p>

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.journey.steps')">
        <ol class="divide-y dark:divide-cherry-800">
            @foreach ($events as $index => $event)
                <li class="flex flex-col gap-1 px-4 py-2.5 text-sm sm:flex-row sm:items-start sm:gap-4">
                    <span class="w-40 shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">
                        {{ substr($event['at'], 11, 12) }}
                        @if ($event['seq'] !== null)
                            · #{{ $event['seq'] }}
                        @endif
                    </span>

                    <div class="flex min-w-0 flex-col gap-0.5">
                        <p class="font-semibold text-gray-800 dark:text-white">
                            @lang('dam::app.admin.analytics.events.'.strtr($event['name'], '.', '-'))
                            @if ($event['outcome'] === 'failure')
                                <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-normal text-red-700 dark:bg-red-900 dark:text-red-200">@lang('dam::app.admin.analytics.journey.failed')</span>
                            @endif
                            <span class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.journey.emitter.'.$event['emitter'])</span>
                        </p>

                        @if ($event['subject'] !== null)
                            <p class="break-words text-gray-600 dark:text-gray-300">{{ $event['subject']['label'] ?? '#'.$event['subject']['id'] }}</p>
                        @endif

                        @php
                            // Joined, so a step without a surface never starts with a separator.
                            $parts = array_values(array_filter([
                                $event['surface'] === null ? null : trans('dam::app.admin.analytics.journey.surface', ['surface' => $event['surface']]),
                                $event['share_id'] === null ? null : trans('dam::app.admin.analytics.engagement.link-id', ['id' => $event['share_id']]),
                                ...array_map(fn ($key, $value) => trans('dam::app.admin.analytics.journey.detail.'.$key).' '.$value, array_keys($event['detail']), $event['detail']),
                                $event['duration'] === null ? null : $event['duration'].' ms',
                            ]));
                        @endphp

                        @if ($parts !== [])
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ implode(' · ', $parts) }}</p>
                        @endif

                        @if ($event['request_id'] !== null)
                            <p class="font-mono text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.reports.health.failures.request'): {{ $event['request_id'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-dam::reports.section>

    @include('dam::reports.analytics.counted', ['notes' => [
        'dam::app.admin.analytics.journey.counted.order',
        'dam::app.admin.analytics.journey.counted.hidden',
        'dam::app.admin.analytics.journey.counted.text',
    ]])
</x-admin::layouts>
