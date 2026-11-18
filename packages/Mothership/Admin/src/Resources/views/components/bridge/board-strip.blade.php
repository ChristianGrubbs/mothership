@props(['text', 'cells' => 14])
{{--
    A one-row split-flap strip (Bridge v2): each character on its own flap tile, padded to $cells.
    Static here; the flipping board is Home's alone (motion v2-docking, rule 2).
--}}
@php($chars = mb_str_split(mb_str_pad(mb_strtoupper(mb_substr($text, 0, $cells)), $cells)))
<div {{ $attributes->merge(['class' => 'bridge-board bridge-board-strip']) }} style="--cells: {{ $cells }}">
    <div class="bridge-frow">
        @foreach ($chars as $char)
            <i>{{ $char === ' ' ? '' : $char }}</i>
        @endforeach
    </div>
</div>
