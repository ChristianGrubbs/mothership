@php($banners = app(\Mothership\Admin\Helpers\PromoBanner::class)->visibleBanners())

@if (! empty($banners))
    <x-admin::promo-bar :banners="$banners" />
@endif
