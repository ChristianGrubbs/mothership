<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.analytics.privacy.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :title="trans('dam::app.admin.analytics.privacy.title')"
        :description="trans('dam::app.admin.analytics.privacy.description')"
    />

    {{-- Your setting first: the one thing on this page anyone can change. --}}
    <section class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow px-4 py-3">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.analytics.privacy.status-heading')</h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" role="status">
            @if (! $enabled)
                @lang('dam::app.admin.analytics.privacy.disabled-status')
            @elseif ($optedOut)
                @lang('dam::app.admin.analytics.privacy.opted-out-status')
            @elseif ($privacySignal)
                @lang('dam::app.admin.analytics.privacy.signal-status')
            @else
                @lang('dam::app.admin.analytics.privacy.opted-in-status')
            @endif
        </p>

        <form method="POST" action="{{ route('admin.dam.analytics.privacy.update') }}" class="mt-3">
            @csrf
            <input type="hidden" name="opt_out" value="{{ $optedOut ? 0 : 1 }}">
            <button type="submit" class="{{ $optedOut ? 'primary-button' : 'secondary-button' }}">
                @lang($optedOut ? 'dam::app.admin.analytics.privacy.opt-in-button' : 'dam::app.admin.analytics.privacy.opt-out-button')
            </button>
        </form>
    </section>

    <section class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow px-4 py-3">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.analytics.privacy.what-title')</h2>

        <ul class="mt-2 flex list-disc flex-col gap-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            @foreach (['staff', 'links', 'search', 'replay', 'never'] as $item)
                <li>@lang('dam::app.admin.analytics.privacy.what.'.$item)</li>
            @endforeach
        </ul>
    </section>

    <section class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow px-4 py-3">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.analytics.privacy.keep-title')</h2>

        <ul class="mt-2 flex list-disc flex-col gap-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>@lang('dam::app.admin.analytics.privacy.keep.raw', ['days' => $retention['raw_days']])</li>
            <li>@lang('dam::app.admin.analytics.privacy.keep.rollup', ['months' => $retention['rollup_months']])</li>
            <li>@lang('dam::app.admin.analytics.privacy.keep.terms', ['days' => $retention['search_terms_days'], 'k' => $k])</li>
            <li>@lang('dam::app.admin.analytics.privacy.keep.forget')</li>
        </ul>
    </section>

    <section class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow px-4 py-3">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.analytics.privacy.choices-title')</h2>

        <ul class="mt-2 flex list-disc flex-col gap-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
            @foreach (['opt-out', 'signal', 'links', 'who'] as $item)
                <li>@lang('dam::app.admin.analytics.privacy.choices.'.$item, ['k' => $k])</li>
            @endforeach
        </ul>

        @if (Route::has('admin.help.docs.show'))
            <p class="mt-3 text-sm">
                <a href="{{ route('admin.help.docs.show', ['slug' => 'usage-analytics']) }}" class="text-violet-700 hover:underline dark:text-violet-300">@lang('dam::app.admin.analytics.privacy.manual-link')</a>
            </p>
        @endif
    </section>
</x-admin::layouts>
