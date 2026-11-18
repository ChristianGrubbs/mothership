{{--
    P5a (D1): an upload link that is unknown, closed or expired. One page for all three (410), naming nothing about
    the request, so a guest learns neither that a link once existed nor why it stopped.
--}}
<x-admin::layouts.anonymous>
    <x-slot:title>
        @lang('dam::app.upload-portal.unavailable.title')
    </x-slot:title>

    @push('styles')
        @mothershipVite(['src/Resources/assets/css/app.css'], 'dam')
    @endpush

    <div class="min-h-screen bg-gray-50 dark:bg-cherry-950 flex flex-col items-center justify-center gap-8 px-6 py-10">
        @include('dam::upload-portal.partials.brand')

        <div class="max-w-md w-full bg-white dark:bg-cherry-900 rounded-lg border border-gray-200 dark:border-cherry-800 p-8 text-center" data-dam-portal-unavailable data-dock="card">
            <div class="mx-auto w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mb-4">
                <span class="icon-dam-link text-3xl text-amber-600 dark:text-amber-400"></span>
            </div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-slate-50 mb-2">@lang('dam::app.upload-portal.unavailable.title')</h1>
            <p class="text-sm text-gray-600 dark:text-slate-300">@lang('dam::app.upload-portal.unavailable.message')</p>
        </div>
    </div>
</x-admin::layouts.anonymous>
