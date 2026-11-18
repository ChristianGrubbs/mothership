{{-- A titled report card (P1b analytics dashboards); the body is the slot. --}}
@props(['title', 'description' => null])

<section {{ $attributes->merge(['class' => 'relative mt-4 rounded-lg bg-white dark:bg-cherry-900 box-shadow overflow-x-auto']) }}>
    <div class="px-4 py-2.5 border-b dark:border-cherry-800">
        <h2 class="text-base font-semibold text-gray-800 dark:text-white">{{ $title }}</h2>
        @if ($description !== null)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $description }}</p>
        @endif
    </div>

    {{ $slot }}
</section>
