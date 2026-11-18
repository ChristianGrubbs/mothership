<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.analytics.journeys.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.analytics.journeys.title')"
        :description="trans('dam::app.admin.analytics.journeys.description')"
    />

    @include('dam::reports.nav', ['current' => $current, 'days' => $days, 'ranges' => $ranges])

    <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
        @lang($identify ? 'dam::app.admin.analytics.journeys.identified' : 'dam::app.admin.analytics.journeys.pseudonymous')
    </p>

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.journeys.sessions')">
        @if ($sessions === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.journeys.who')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.journeys.started')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.journeys.ended')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.journeys.events')</x-admin::table.th>
                        <x-admin::table.th><span class="sr-only">@lang('dam::app.admin.analytics.journeys.open')</span></x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($sessions as $row)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td>{{ $row['actor'] }}</x-admin::table.td>
                            <x-admin::table.td class="whitespace-nowrap">{{ substr($row['started'], 0, 19) }}</x-admin::table.td>
                            <x-admin::table.td class="whitespace-nowrap">{{ substr($row['ended'], 0, 19) }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['events'] }}</x-admin::table.td>
                            <x-admin::table.td>
                                <a href="{{ route('admin.dam.reports.journey', ['session' => $row['session_id'], 'days' => $days]) }}" class="text-violet-700 dark:text-violet-300 hover:underline">
                                    @lang('dam::app.admin.analytics.journeys.open')
                                </a>
                            </x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </x-dam::reports.section>

    @include('dam::reports.analytics.counted', ['notes' => [
        'dam::app.admin.analytics.journeys.counted.sessions',
        'dam::app.admin.analytics.journeys.counted.identity',
        'dam::app.admin.analytics.journeys.counted.retention',
    ]])
</x-admin::layouts>
