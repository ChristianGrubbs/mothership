{{-- P5a (D1): the portal's brand line. Not a link: the portal leads nowhere else in the DAM. --}}
<div class="flex items-center gap-3" data-dam-portal-brand data-dock="rise">
    @if ($logo = core()->getConfigData('general.design.admin_logo.logo_image'))
        <img class="h-8" src="{{ Storage::url($logo) }}" alt="{{ config('documentation.name') }}" />
    @else
        <img class="h-8 block dark:hidden" src="{{ mothership_asset('images/logo.svg') }}" alt="{{ config('documentation.name') }}" />
        <img class="h-8 hidden dark:block" src="{{ mothership_asset('images/dark_logo.svg') }}" alt="{{ config('documentation.name') }}" />
    @endif
</div>
