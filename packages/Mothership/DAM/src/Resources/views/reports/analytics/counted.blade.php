{{-- "How this is counted": every dashboard says what a number means and what it leaves out. --}}
<div class="mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow px-4 py-2.5">
    <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.analytics.counted-title')</p>

    <ul class="mt-2 flex flex-col gap-1 text-sm text-gray-600 dark:text-gray-300">
        @foreach ($notes as $note)
            <li>@lang($note, ['k' => config('dam_events.k', 3)])</li>
        @endforeach
        <li>@lang('dam::app.admin.analytics.counted-scope')</li>
        <li>
            @lang('dam::app.admin.analytics.counted-privacy')
            <a href="{{ route('admin.dam.analytics.privacy') }}" class="text-violet-700 dark:text-violet-300 hover:underline">@lang('dam::app.admin.analytics.privacy.title')</a>
        </li>
    </ul>
</div>
