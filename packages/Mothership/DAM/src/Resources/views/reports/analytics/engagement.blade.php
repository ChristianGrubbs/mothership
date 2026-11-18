<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.analytics.engagement.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.analytics.engagement.title')"
        :description="trans('dam::app.admin.analytics.engagement.description')"
    />

    @include('dam::reports.nav', ['current' => $current, 'days' => $days, 'ranges' => $ranges])

    @include('dam::reports.analytics.tiles', ['tiles' => [
        'dam::app.admin.analytics.engagement.views'        => $totals['views'],
        'dam::app.admin.analytics.engagement.viewer-days'  => $totals['viewer_days'],
        'dam::app.admin.analytics.engagement.downloads'    => $totals['downloads'],
        'dam::app.admin.analytics.engagement.visits'       => $totals['visits'],
        'dam::app.admin.analytics.engagement.unrefined'    => $unrefined,
        'dam::app.admin.analytics.engagement.never-viewed' => trans('dam::app.admin.analytics.engagement.of-visible', ['count' => $never_viewed, 'visible' => $visible]),
    ]])

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.engagement.assets')" :description="trans('dam::app.admin.analytics.engagement.assets-description')">
        @if ($assets === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.asset')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.views')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.viewer-days')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.downloads')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.previews')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.visits')</x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($assets as $row)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td class="max-w-[320px] break-words">{{ $row['label'] ?? '#'.$row['id'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['views'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['viewer_days'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['downloads'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['previews'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['visits'] }}</x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </x-dam::reports.section>

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.engagement.links')" :description="trans('dam::app.admin.analytics.engagement.links-description')">
        @if ($links === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.link')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.target')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.visits')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.visitor-days')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.views')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.downloads')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.nojs')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.unrefined')</x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($links as $row)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td class="max-w-[240px] break-words">
                                {{ $row['name'] ?: trans('dam::app.admin.analytics.engagement.link-id', ['id' => $row['id']]) }}
                                @unless ($row['active'])
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.engagement.inactive')</span>
                                @endunless
                            </x-admin::table.td>
                            <x-admin::table.td class="max-w-[240px] break-words">{{ $row['target'] ?? trans('dam::app.admin.reports.none') }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['visits'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['visitor_days'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['views'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['downloads'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['nojs'] }}</x-admin::table.td>
                            <x-admin::table.td>{{ $row['unrefined'] }}</x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </x-dam::reports.section>

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.daily')">
        <x-admin::table>
            <x-admin::table.thead>
                <tr>
                    <x-admin::table.th>@lang('dam::app.admin.reports.login-access.day')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.views')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.downloads')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.analytics.engagement.visits')</x-admin::table.th>
                </tr>
            </x-admin::table.thead>

            <tbody>
                @foreach ($daily as $row)
                    <tr class="border-b dark:border-cherry-800">
                        <x-admin::table.td>{{ $row['date'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['views'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['downloads'] }}</x-admin::table.td>
                        <x-admin::table.td>{{ $row['visits'] }}</x-admin::table.td>
                    </tr>
                @endforeach
            </tbody>
        </x-admin::table>
    </x-dam::reports.section>

    @include('dam::reports.analytics.counted', ['notes' => [
        'dam::app.admin.analytics.engagement.counted.views',
        'dam::app.admin.analytics.engagement.counted.downloads',
        'dam::app.admin.analytics.engagement.counted.visits',
        'dam::app.admin.analytics.engagement.counted.unrefined',
        'dam::app.admin.analytics.counted-rollup',
    ]])
</x-admin::layouts>
