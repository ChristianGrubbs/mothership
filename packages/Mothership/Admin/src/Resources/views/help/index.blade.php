<x-admin::layouts>
    <x-slot:title>{{ config('documentation.name') }} help</x-slot>

    <main class="max-w-[1240px] space-y-6 text-gray-800 dark:text-white" data-documentation>
        <h1 class="text-2xl font-semibold">{{ config('documentation.name') }} help</h1>

        <p>Documentation language: English</p>
        @if (bouncer()->hasPermission('configuration'))
            <a href="{{ route('admin.help.api') }}">API reference and downloads</a>
        @endif

        <form method="get" action="{{ route('admin.help.index') }}" class="flex gap-3">
            <label for="documentation-search" class="sr-only">@lang('admin::app.help.index.search-documentation')</label>
            <input id="documentation-search" name="q" type="search" placeholder="@lang('admin::app.help.index.search-documentation')" maxlength="160" value="{{ $query }}" class="w-full rounded border p-3 dark:bg-cherry-900" />
            <button type="submit" class="primary-button">Search</button>
        </form>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($entries as $item)
                <a class="block rounded-lg border p-5 dark:border-cherry-800" href="{{ route('admin.help.docs.show', ['slug' => $item['slug']]) }}">
                    <span class="text-sm">{{ $item['category'] }}</span>
                    <h2 class="text-lg font-semibold">{{ $item['title'] }}</h2>
                    <p>{{ $item['summary'] }}</p>
                </a>
            @empty
                <p role="status">No articles found. Try a shorter search.</p>
            @endforelse
        </div>
    </main>
</x-admin::layouts>
