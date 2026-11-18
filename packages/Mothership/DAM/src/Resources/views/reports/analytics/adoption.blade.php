<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.analytics.adoption.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.analytics.adoption.title')"
        :description="trans('dam::app.admin.analytics.adoption.description')"
    />

    @include('dam::reports.nav', ['current' => $current, 'days' => $days, 'ranges' => $ranges, 'rangeQuery' => $roleId === null ? [] : ['role' => $roleId]])

    {{-- A plain GET form: the role narrows every figure below. --}}
    <form method="GET" action="{{ route('admin.dam.reports.adoption') }}" class="mt-4 flex flex-wrap items-end gap-2.5">
        <input type="hidden" name="days" value="{{ $days }}">

        <label class="flex flex-col gap-1 text-sm text-gray-600 dark:text-gray-300">
            @lang('dam::app.admin.analytics.adoption.role')
            <select name="role" class="rounded-md border px-3 py-2 text-sm text-gray-800 dark:border-cherry-800 dark:bg-cherry-900 dark:text-white">
                <option value="">@lang('dam::app.admin.analytics.adoption.all-roles')</option>
                @foreach ($roles as $id => $name)
                    <option value="{{ $id }}" @selected($roleId === (int) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <button type="submit" class="secondary-button">@lang('dam::app.admin.analytics.adoption.apply')</button>
    </form>

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.adoption.features')" :description="trans('dam::app.admin.analytics.adoption.features-description')">
        <x-admin::table>
            <x-admin::table.thead>
                <tr>
                    <x-admin::table.th>@lang('dam::app.admin.analytics.adoption.feature')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.analytics.adoption.actors')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.analytics.adoption.uses')</x-admin::table.th>
                    <x-admin::table.th>@lang('dam::app.admin.analytics.adoption.repeat')</x-admin::table.th>
                </tr>
            </x-admin::table.thead>

            <tbody>
                @foreach ($features as $feature => $row)
                    <tr class="border-b dark:border-cherry-800">
                        <x-admin::table.td>@lang('dam::app.admin.analytics.features.'.$feature)</x-admin::table.td>
                        @foreach (['actors', 'uses', 'repeat'] as $measure)
                            <x-admin::table.td class="{{ $row[$measure] === null ? 'italic text-gray-500 dark:text-gray-400' : '' }}">{{ $row[$measure] ?? trans('dam::app.admin.analytics.suppressed') }}</x-admin::table.td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </x-admin::table>
    </x-dam::reports.section>

    <x-dam::reports.section :title="trans('dam::app.admin.analytics.adoption.weekly')" :description="trans('dam::app.admin.analytics.adoption.weekly-description')">
        @if ($weeks === [])
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">@lang('dam::app.admin.analytics.empty')</p>
        @else
            <x-admin::table>
                <x-admin::table.thead>
                    <tr>
                        <x-admin::table.th>@lang('dam::app.admin.analytics.adoption.week')</x-admin::table.th>
                        @foreach (array_keys($features) as $feature)
                            <x-admin::table.th>@lang('dam::app.admin.analytics.features.'.$feature)</x-admin::table.th>
                        @endforeach
                    </tr>
                </x-admin::table.thead>

                <tbody>
                    @foreach ($weeks as $week => $row)
                        <tr class="border-b dark:border-cherry-800">
                            {{-- The key is already a calendar date; parse it in the app timezone without UTC conversion. --}}
                            <x-admin::table.td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $week, config('app.timezone'))->translatedFormat('F j, Y') }}</x-admin::table.td>
                            @foreach ($row as $actors)
                                <x-admin::table.td class="{{ $actors === null ? 'italic text-gray-500 dark:text-gray-400' : '' }}">{{ $actors ?? trans('dam::app.admin.analytics.suppressed') }}</x-admin::table.td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </x-admin::table>
        @endif
    </x-dam::reports.section>

    @include('dam::reports.analytics.counted', ['notes' => [
        'dam::app.admin.analytics.adoption.counted.features',
        'dam::app.admin.analytics.adoption.counted.repeat',
        'dam::app.admin.analytics.adoption.counted.suppressed',
        'dam::app.admin.analytics.adoption.counted.staff',
    ]])
</x-admin::layouts>
