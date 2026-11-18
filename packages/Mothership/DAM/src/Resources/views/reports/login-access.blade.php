<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.reports.login-access.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.reports.login-access.title')"
        :description="trans('dam::app.admin.reports.login-access.description')"
    />

    @include('dam::reports.nav', ['current' => 'login-access', 'days' => $days, 'ranges' => $ranges])

    <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-x-auto">
        <x-admin::table>
            <x-admin::table.thead>
                <tr>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.day')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.succeeded')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.failed')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.locked-out')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.logouts')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.active-admins')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.sessions')</x-admin::table.th>
                </tr>
            </x-admin::table.thead>

            <tbody>
                <tr class="border-b dark:border-cherry-800 font-semibold">
                    <x-admin::table.td>@lang('dam::app.admin.reports.login-access.total')</x-admin::table.td>
                    <x-admin::table.td>{{ $totals['succeeded'] }}</x-admin::table.td>
                    <x-admin::table.td>{{ $totals['failed'] }}</x-admin::table.td>
                    <x-admin::table.td>{{ $totals['locked_out'] }}</x-admin::table.td>
                    <x-admin::table.td>{{ $totals['logouts'] }}</x-admin::table.td>
                    <x-admin::table.td>@lang('dam::app.admin.reports.none')</x-admin::table.td>
                    <x-admin::table.td>@lang('dam::app.admin.reports.none')</x-admin::table.td>
                </tr>

                @foreach ($daily as $row)
                    <tr class="border-b dark:border-cherry-800">
                        <x-admin::table.td>{{ $row['date'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['succeeded'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['failed'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['locked_out'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['logouts'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['active_admins'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['sessions'] }}</x-admin::table.td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin::table>
    </div>

    <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-x-auto">
        <div class="px-4 py-2.5 border-b dark:border-cherry-800">
            <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.reports.login-access.identifiers.title')</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('dam::app.admin.reports.login-access.identifiers.description')</p>
        </div>

        @if ($identifiers === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.reports.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.reports.login-access.identifiers.prefix')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.login-access.failed')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.login-access.locked-out')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.login-access.identifiers.known-account')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.reports.login-access.identifiers.last')</x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($identifiers as $identifier)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td class="font-mono">
                                {{ $identifier['prefix'] === '' ? trans('dam::app.admin.reports.login-access.identifiers.unknown') : $identifier['prefix'] }}
                            </x-admin::table.td>
                            <x-admin::table.td>{{ $identifier['failed'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $identifier['locked_out'] }}</x-admin::table.td>
                            <x-admin::table.td>@lang('dam::app.admin.reports.login-access.identifiers.'.($identifier['known_account'] ? 'yes' : 'no'))</x-admin::table.td>
                            <x-admin::table.td>{{ $identifier['last'] }}</x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </div>

    <div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow px-4 py-2.5">
        <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.reports.login-access.counted.title')</p>

        <ul class="mt-2 flex flex-col gap-1 text-sm text-gray-600 dark:text-gray-300">
            @foreach (['logins', 'failures', 'active', 'sessions', 'limits', 'scope'] as $note)
                <li>@lang('dam::app.admin.reports.login-access.counted.'.$note)</li>
            @endforeach
        </ul>
    </div>
</x-admin::layouts>
