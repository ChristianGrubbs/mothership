<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.analytics.friction.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.analytics.friction.title')"
        :description="trans('dam::app.admin.analytics.friction.description')"
    />

    @include('dam::reports.nav', ['current' => $current, 'days' => $days, 'ranges' => $ranges])

    @php
        $funnels = [
            'upload' => [
                'dialogs'   => $upload['dialogs'],
                'abandoned' => $upload['abandoned'],
                'uploads'   => $upload['uploads'],
                'attempts'  => $upload['attempts'],
                'succeeded' => $upload['succeeded'],
                'failed'    => $upload['failed'],
            ],
            'share' => [
                'dialogs'   => $share['dialogs'],
                'abandoned' => $share['abandoned'],
                'created'   => $share['created'],
                'revoked'   => $share['revoked'],
                'visited'   => $share['visited'],
            ],
            'search' => [
                'submitted' => $search['submitted'],
                'zero'      => $search['zero'],
                'opened'    => $search['opened'],
                'served'    => $search['served'],
            ],
        ];
    @endphp

    @include('dam::reports.analytics.tiles', ['tiles' => [
        'dam::app.admin.analytics.friction.upload.completion' => $upload['completion'] === null ? trans('dam::app.admin.reports.none') : $upload['completion'].'%',
        'dam::app.admin.analytics.friction.upload.uploads'    => $upload['uploads'],
        'dam::app.admin.analytics.friction.upload.failed'     => $upload['failed'],
        'dam::app.admin.analytics.friction.share.created'     => $share['created'],
        'dam::app.admin.analytics.friction.search.submitted'  => $search['submitted'],
        'dam::app.admin.analytics.friction.search.zero'       => $search['zero'],
    ]])

    <div class="grid gap-x-4 xl:grid-cols-3">
        @foreach ($funnels as $funnel => $steps)
            <x-dam::reports.section :title="trans('dam::app.admin.analytics.friction.'.$funnel.'.title')">
                <dl class="divide-y dark:divide-cherry-800">
                    @foreach ($steps as $step => $value)
                        <div class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                            <dt class="text-gray-600 dark:text-gray-300">@lang('dam::app.admin.analytics.friction.'.$funnel.'.'.$step)</dt>
                            <dd class="font-semibold text-gray-800 dark:text-white">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-dam::reports.section>
        @endforeach
    </div>

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.friction.codes')" :description="trans('dam::app.admin.analytics.friction.codes-description')">
        @if ($upload['codes'] === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.reports.health.code')</x-admin::table.th>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.friction.failures')</x-admin::table.th>
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($upload['codes'] as $code => $count)
                        <tr class="border-b dark:border-cherry-800">
                            <x-admin::table.td class="font-mono">{{ $code }}</x-admin::table.td>
                            <x-admin::table.td>{{ $count }}</x-admin::table.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </x-dam::reports.section>

    <div class="grid gap-x-4 xl:grid-cols-2">
        @foreach (['terms' => $terms, 'zero' => $zero] as $list => $rows)
            <x-dam::reports.section :title="trans('dam::app.admin.analytics.friction.'.$list)" :description="trans('dam::app.admin.analytics.friction.terms-description', ['k' => config('dam_events.k', 3)])">
                @if ($rows === [])
                    <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.empty')</p>
                @else
                    <x-admin::table>
                        <x-admin::table.thead>
                            <tr>
                                <x-admin::table.th>@lang('dam::app.admin.analytics.friction.term')</x-admin::table.th>
                                <x-admin::table.th>@lang('dam::app.admin.analytics.friction.searches')</x-admin::table.th>
                                <x-admin::table.th>@lang('dam::app.admin.analytics.friction.no-results')</x-admin::table.th>
                            </tr>
                        </x-admin::table.thead>

                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b dark:border-cherry-800">
                                    <x-admin::table.td class="max-w-[280px] break-words {{ $row['term'] === null ? 'italic text-gray-500 dark:text-gray-400' : '' }}">{{ $row['term'] ?? trans('dam::app.admin.analytics.friction.suppressed-terms') }}</x-admin::table.td>
                                    <x-admin::table.td>{{ $row['searches'] }}</x-admin::table.td>
                                    <x-admin::table.td>{{ $row['zero_results'] }}</x-admin::table.td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-admin::table>
                @endif
            </x-dam::reports.section>
        @endforeach
    </div>

    @include('dam::reports.analytics.counted', ['notes' => [
        'dam::app.admin.analytics.friction.counted.upload',
        'dam::app.admin.analytics.friction.counted.share',
        'dam::app.admin.analytics.friction.counted.search',
        'dam::app.admin.analytics.friction.counted.terms',
    ]])
</x-admin::layouts>
