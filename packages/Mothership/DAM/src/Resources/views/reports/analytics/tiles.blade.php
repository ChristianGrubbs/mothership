{{-- A row of headline figures. $tiles: lang key => value; null shows as suppressed (fewer than k people). --}}
<div class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-6">
    @foreach ($tiles as $label => $value)
        <div class="rounded-lg bg-white dark:bg-cherry-900 box-shadow px-4 py-3">
            <p class="text-xs text-gray-600 dark:text-gray-300">@lang($label)</p>
            <p class="mt-1 text-xl font-semibold text-gray-800 dark:text-white">{{ $value ?? trans('dam::app.admin.analytics.suppressed') }}</p>
        </div>
    @endforeach
</div>
