<x-admin::layouts.anonymous>
    <x-slot:title>{{ __('admin_api::mcp-oauth.title') }}</x-slot>
    <main class="flex min-h-screen w-full items-center justify-center p-6">
      <section class="w-full max-w-[400px] rounded-xl border border-gray-100 bg-white p-6 dark:border-cherry-700 dark:bg-cherry-800 sm:p-8" aria-labelledby="mcp-title">
        <h1 id="mcp-title" class="text-2xl font-bold text-gray-800 dark:text-white">{{ __('admin_api::mcp-oauth.title') }}</h1>
        <p class="mt-4 break-words text-gray-800 dark:text-white">{{ __('admin_api::mcp-oauth.request', ['client' => $client->name]) }}</p>
        <p class="mt-4 break-words text-gray-800 dark:text-white">{{ __('admin_api::mcp-oauth.account', ['name' => $user->name, 'email' => $user->email]) }}</p>
        <p class="mt-4 mb-6 text-sm text-gray-600 dark:text-gray-300">{{ __('admin_api::mcp-oauth.access') }}</p>
        <form method="post" action="{{ route('mcp.oauth.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button class="primary-button min-h-11 w-full justify-center" type="submit">{{ __('admin_api::mcp-oauth.approve') }}</button>
        </form>
        <form class="mt-4" method="post" action="{{ route('mcp.oauth.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button class="secondary-button min-h-11 w-full justify-center" type="submit">{{ __('admin_api::mcp-oauth.deny') }}</button>
        </form>
      </section>
    </main>
</x-admin::layouts.anonymous>
