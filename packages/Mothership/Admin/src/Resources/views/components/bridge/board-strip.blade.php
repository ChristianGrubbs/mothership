@props(['text', 'cells' => 14])
{{--
    A one-row split-flap strip (Bridge v2): each character on its own flap tile, padded to $cells.
    Add data-flap to flip it in once on load (docking.js). The cells are hidden from assistive technology, so
    every use gives its words once in real text or an accessible name.
--}}
@php($chars = mb_str_split(mb_str_pad(mb_strtoupper(mb_substr($text, 0, $cells)), $cells)))
<div {{ $attributes->merge(['class' => 'bridge-board bridge-board-strip']) }} style="--cells: {{ $cells }}">
    <div class="bridge-frow" aria-hidden="true">
        @foreach ($chars as $char)
            <i>{{ $char === ' ' ? '' : $char }}</i>
        @endforeach
    </div>
</div>
