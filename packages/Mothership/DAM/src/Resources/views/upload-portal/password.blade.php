{{--
    P5a (D1): a password-protected upload link's prompt. It names nothing about the request (no title, folder or
    expiry) until the password is right, and posts only to this link's own unlock route.
--}}
<x-admin::layouts.anonymous>
    <x-slot:title>
        @lang('dam::app.upload-portal.password.title')
    </x-slot:title>

    @php
        $error = $error ?? null;
    @endphp

    <div class="min-h-screen bg-gray-50 dark:bg-cherry-950 flex flex-col items-center justify-center gap-8 px-6 py-10">
        @include('dam::upload-portal.partials.brand')

        <div class="max-w-md w-full bg-white dark:bg-cherry-900 rounded-lg border border-gray-200 dark:border-cherry-800 p-8" data-dam-portal-password data-dock="card">
            <div class="mx-auto w-14 h-14 rounded-full bg-primary-50 dark:bg-primary-900 flex items-center justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-primary-700 dark:text-primary-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                </svg>
            </div>

            <h1 class="text-xl font-semibold text-zinc-900 dark:text-slate-50 mb-2 text-center">@lang('dam::app.upload-portal.password.title')</h1>
            <p class="text-sm text-gray-600 dark:text-slate-300 text-center">@lang('dam::app.upload-portal.password.message')</p>

            <form method="POST" action="{{ route('dam.upload_portal.unlock', ['token' => $token]) }}" class="mt-6 flex flex-col gap-3">
                @csrf

                <label for="dam-portal-password" class="block text-xs font-medium text-gray-600 dark:text-slate-300">@lang('dam::app.upload-portal.password.label')</label>

                <input
                    id="dam-portal-password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    autofocus
                    @if ($error) aria-invalid="true" aria-describedby="dam-portal-password-error" @endif
                    @disabled($error === 'locked')
                    class="min-h-[44px] w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500 dark:focus:border-primary-400"
                >

                @if ($error)
                    <p id="dam-portal-password-error" role="alert" class="text-sm text-red-600 dark:text-red-400" data-dam-portal-password-error="{{ $error }}">
                        {{ $error === 'locked'
                            ? ((int) ($retryAfter ?? 60) > 120
                                ? trans('dam::app.upload-portal.password.locked-minutes', ['minutes' => (int) ceil(((int) $retryAfter) / 60)])
                                : trans('dam::app.upload-portal.password.locked', ['seconds' => (int) ($retryAfter ?? 60)]))
                            : trans('dam::app.upload-portal.password.wrong') }}
                    </p>
                @endif

                <button type="submit" class="primary-button min-h-[44px] justify-center" @disabled($error === 'locked')>@lang('dam::app.upload-portal.password.submit')</button>
            </form>
        </div>
    </div>
</x-admin::layouts.anonymous>
