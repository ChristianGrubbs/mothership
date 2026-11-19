@props([
    'icon'        => null,
    'title',
    'description' => null,
    // False inside a list card (the Away from ship and Sorting lists): the card is the frame, so the dashed box goes
    // and the glyph sits in a neutral tile. The slot holds the one action that fills the list, where there is one.
    'framed'      => true,
])

@if ($framed)
    <div {{ $attributes->merge(['class' => 'flex min-h-[140px] flex-col items-center justify-center rounded-md border border-dashed border-gray-300 px-6 py-8 text-center dark:border-cherry-800']) }}>
        @if ($icon)
            <span class="{{ $icon }} inline-flex text-3xl text-gray-400 dark:text-gray-500"></span>
        @endif

        <p class="{{ $icon ? 'mt-2 ' : '' }}text-sm font-semibold text-gray-700 dark:text-gray-200">
            {{ $title }}
        </p>

        @if ($description)
            <p class="mt-2 max-w-md text-xs leading-5 text-gray-500 dark:text-gray-300">
                {{ $description }}
            </p>
        @endif

        @if ($slot->isNotEmpty())
            <div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
        @endif
    </div>
@else
    <div {{ $attributes->merge(['class' => 'bridge-list-empty']) }}>
        @if ($icon)
            <span class="bridge-list-tile {{ $icon }}" aria-hidden="true"></span>
        @endif

        <p class="bridge-list-empty-title">{{ $title }}</p>

        @if ($description)
            <p class="bridge-list-empty-text">{{ $description }}</p>
        @endif

        @if ($slot->isNotEmpty())
            <div class="bridge-list-empty-action">{{ $slot }}</div>
        @endif
    </div>
@endif
