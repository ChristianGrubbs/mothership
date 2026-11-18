<x-admin::layouts.anonymous>
    <x-slot:title>
        {{-- The tab title says what the heading says: a revoked link was revoked, not expired (UX eval 2026-10-10, PERM-12). --}}
        {{ match (true) {
            $share->isRevoked()      => trans('dam::app.share.public.revoked-title'),
            $notAvailable ?? false   => trans('dam::app.share.public.unavailable-title'),
            default                  => trans('dam::app.share.public.expired-title'),
        } }}
    </x-slot:title>

    @push('styles')
        {{-- The anonymous layout never loads the DAM bundle by itself; without it the icon font (icon-dam-link) is blank. --}}
        @mothershipVite(['src/Resources/assets/css/app.css'], 'dam')
    @endpush

    @php
        $isRevoked = $share->isRevoked();
        $notAvailable = ! $isRevoked && ($notAvailable ?? false);
        $title = match (true) {
            $isRevoked    => trans('dam::app.share.public.revoked-title'),
            $notAvailable => trans('dam::app.share.public.unavailable-title'),
            default       => trans('dam::app.share.public.expired-title'),
        };
        $message = match (true) {
            $isRevoked    => trans('dam::app.share.public.revoked-message'),
            $notAvailable => trans('dam::app.share.public.unavailable-message'),
            default       => trans('dam::app.share.public.expired-message'),
        };
    @endphp

    <div class="min-h-screen bg-gray-50 dark:bg-cherry-950 flex items-center justify-center px-6">
        <div class="max-w-md w-full bg-white dark:bg-cherry-900 rounded-lg border border-gray-200 dark:border-cherry-800 p-8 text-center" data-dock="card">
            <div class="mx-auto w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mb-4">
                <span class="icon-dam-link text-3xl text-amber-600 dark:text-amber-400"></span>
            </div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-slate-50 mb-2">
                {{ $title }}
            </h1>
            <p class="text-sm text-gray-600 dark:text-slate-300">
                {{ $message }}
            </p>
        </div>
    </div>
</x-admin::layouts.anonymous>
