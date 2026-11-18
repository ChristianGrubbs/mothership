@props(['provider'])

<a
    href="{{ $provider->getRedirectUrl() }}"
    {{-- Drawn to the provider's own branding rules (light: white fill, #747775 stroke, #1F1F1F text; dark: #131314 fill, #8E918F stroke, #E3E3E3 text) with its own mark. --}}
    class="bridge-provider-btn"
    aria-label="{{ $provider->getLabel() }}"
>
    @if ($icon = $provider->getIconView())
        @include($icon)
    @endif

    {{ $provider->getLabel() }}
</a>
