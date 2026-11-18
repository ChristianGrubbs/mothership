<x-admin::layouts.anonymous>
    <x-slot:title>{{ __('admin_api::mcp-oauth.restart-title') }}</x-slot>
    <main class="flex min-h-screen w-full items-center justify-center p-6">
        <section class="w-full max-w-[400px] rounded-xl border border-gray-100 bg-white p-6 dark:border-cherry-700 dark:bg-cherry-800 sm:p-8" aria-labelledby="mcp-restart-title" data-dock="card">
            <h1 id="mcp-restart-title" class="text-2xl font-bold text-gray-800 dark:text-white">{{ __('admin_api::mcp-oauth.restart-title') }}</h1>
            <p class="mt-4 mb-6 text-sm text-gray-600 dark:text-gray-300">{{ __($restart ? 'admin_api::mcp-oauth.restart-message' : 'admin_api::mcp-oauth.restart-expired') }}</p>
            @if ($restart)
                <a href="{{ $restart }}" class="primary-button min-h-11 w-full justify-center">{{ __('admin_api::mcp-oauth.restart') }}</a>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('admin_api::mcp-oauth.restart-client') }}</p>
            @endif
        </section>
    </main>
</x-admin::layouts.anonymous>
