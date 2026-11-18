{{-- P4 D2: a collection that has expired or been archived, as its audience sees it (410). Nothing of its members. --}}
<x-admin::layouts>
    <x-slot:title>@lang('dam::app.admin.dam.collections.closed.title')</x-slot>

    <div class="mx-auto max-w-xl py-16 text-center" data-collection-closed>
        <span class="icon-catalog text-5xl text-gray-400" aria-hidden="true"></span>
        <h1 class="mt-4 text-xl font-semibold text-gray-800 dark:text-white">@lang('dam::app.admin.dam.collections.closed.title')</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">@lang('dam::app.admin.dam.collections.closed.message')</p>
        <a href="{{ route('admin.dam.collections.index', ['tab' => 'shared']) }}" class="secondary-button mt-6 inline-flex">
            @lang('dam::app.admin.dam.collections.closed.back')
        </a>
    </div>
</x-admin::layouts>
