<x-admin::layouts>
    <x-slot:title>{{ config('documentation.name') }} help</x-slot>

    <main class="max-w-[1240px] space-y-6 text-gray-800 dark:text-white" data-documentation>
        <h1 class="text-2xl font-semibold">{{ config('documentation.name') }} help</h1>

        <p>Documentation language: English</p>

        <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <nav aria-label="Documentation" class="lg:sticky lg:top-4 lg:self-start">
                <ul class="flex flex-col gap-0.5 rounded-lg border border-gray-200 bg-white p-2 dark:border-cherry-800 dark:bg-cherry-900">
                    @foreach ($entries as $item)
                        <li>
                            <a
                                href="{{ route('admin.help.docs.show', ['slug' => $item['slug']]) }}"
                                class="block rounded-md px-3 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-cherry-800 {{ $item['slug'] === $entry['slug'] ? 'bg-primary-50 font-semibold text-primary-700 dark:bg-primary-900/30 dark:text-primary-200' : 'text-gray-700 dark:text-slate-200' }}"
                                @if ($item['slug'] === $entry['slug']) aria-current="page" @endif
                            >
                                {{ $item['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <article class="min-w-0" data-documentation-article>{!! $html !!}</article>
        </div>
    </main>

    @include('admin::help.reader-script')
</x-admin::layouts>
