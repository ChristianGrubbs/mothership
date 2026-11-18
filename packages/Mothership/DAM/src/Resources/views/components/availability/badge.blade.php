@props(['reason' => null])

{{-- P2 S3: why an asset is not out for use, for the owner, editors and full admins who still see it. --}}
@if ($reason)
    <span
        {{ $attributes->merge(['class' => 'inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold leading-4 bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200']) }}
        data-dam-availability-badge="{{ $reason }}"
    >
        @lang('dam::app.admin.dam.asset.availability.badges.'.$reason)
    </span>
@endif
