{{--
    P2 S5b: a password-protected link's prompt. It names nothing about what the link opens (no file or folder name,
    no expiry) until the password is right, and posts only to this link's own unlock route.
--}}
<x-admin::layouts.anonymous>
    <x-slot:title>
        @lang('dam::app.share.public.gate.password-title')
    </x-slot:title>

    @php
        $error = $error ?? null;
    @endphp

    <div class="min-h-screen bg-gray-50 dark:bg-cherry-950 flex items-center justify-center px-6">
        <div class="max-w-md w-full bg-white dark:bg-cherry-900 rounded-lg border border-gray-200 dark:border-cherry-800 p-8" data-dam-share-password data-dock="card">
            <div class="mx-auto w-14 h-14 rounded-full bg-primary-50 dark:bg-primary-900 flex items-center justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-primary-700 dark:text-primary-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                </svg>
            </div>

            <h1 class="text-xl font-semibold text-zinc-900 dark:text-slate-50 mb-2 text-center">
                @lang('dam::app.share.public.gate.password-title')
            </h1>

            <p class="text-sm text-gray-600 dark:text-slate-300 text-center">
                @lang('dam::app.share.public.gate.password-message')
            </p>

            <form
                method="POST"
                action="{{ route('dam.share.unlock', ['token' => $share->urlToken()]) }}"
                class="mt-6 flex flex-col gap-3"
            >
                @csrf

                @if ($assetId ?? null)
                    <input type="hidden" name="asset_id" value="{{ $assetId }}">
                @endif

                <label for="dam-share-password" class="block text-xs font-medium text-gray-600 dark:text-slate-300">
                    @lang('dam::app.share.public.gate.password-label')
                </label>

                <input
                    id="dam-share-password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    autofocus
                    @if ($error) aria-invalid="true" aria-describedby="dam-share-password-error" @endif
                    @disabled($error === 'locked')
                    class="w-full rounded-md border border-gray-300 dark:border-cherry-700 bg-white dark:bg-cherry-900 px-3 py-2 text-sm text-gray-700 dark:text-slate-200 focus:outline-none focus:border-primary-500 dark:focus:border-primary-400"
                >

                @if ($error)
                    <p id="dam-share-password-error" role="alert" class="text-sm text-red-600 dark:text-red-400" data-dam-share-password-error="{{ $error }}">
                        {{ $error === 'locked'
                            ? trans('dam::app.share.public.gate.password-locked', ['seconds' => (int) ($retryAfter ?? 60)])
                            : trans('dam::app.share.public.gate.password-wrong') }}
                    </p>
                @endif

                <button type="submit" class="primary-button justify-center" @disabled($error === 'locked')>
                    @lang('dam::app.share.public.gate.password-submit')
                </button>
            </form>
        </div>
    </div>
</x-admin::layouts.anonymous>
