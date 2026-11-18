<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.reports.health.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.reports.health.title')"
        :description="trans('dam::app.admin.reports.health.description')"
    />

    @include('dam::reports.nav', ['current' => 'operational-health', 'days' => $days, 'ranges' => $ranges])

    @if ($scoped)
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">@lang('dam::app.admin.reports.health.scoped')</p>
    @endif

    <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-x-auto">
        <div class="px-4 py-2.5 border-b dark:border-cherry-800">
            <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.reports.health.statuses')</p>
        </div>

        @if ($statuses === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.reports.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.table')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.status')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.code')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.count')</x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($statuses as $row)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td>@lang('dam::app.admin.reports.health.tables.'.$row['table'])</x-admin::table.td>
                            <x-admin::table.td>{{ $row['status'] }}</x-admin::table.td>
                            <x-admin::table.td class="font-mono">{{ $row['code'] ?? trans('dam::app.admin.reports.none') }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['count'] }}</x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </div>

    <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-x-auto">
        <div class="px-4 py-2.5 border-b dark:border-cherry-800">
            <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.reports.health.failures.title')</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('dam::app.admin.reports.health.failures.description')</p>
        </div>

        @if ($failures === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.reports.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.table')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.failures.id')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.status')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.code')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.failures.when')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.failures.request')</x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($failures as $row)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td>@lang('dam::app.admin.reports.health.tables.'.$row['table'])</x-admin::table.td>
                            <x-admin::table.td class="font-mono">{{ $row['table'] }}#{{ $row['id'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['status'] }}</x-admin::table.td>
                            <x-admin::table.td class="font-mono">{{ $row['code'] ?? trans('dam::app.admin.reports.none') }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['at'] }}</x-admin::table.td>
                            <x-admin::table.td class="font-mono">{{ $row['request_id'] ?? trans('dam::app.admin.reports.none') }}</x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </div>

    <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-x-auto">
        <div class="px-4 py-2.5 border-b dark:border-cherry-800">
            <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.reports.health.events.title')</p>
        </div>

        @if ($events === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.reports.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.events.event')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.count')</x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($events as $row)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td class="font-mono">{{ $row['event'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['count'] }}</x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </div>
</x-admin::layouts>
