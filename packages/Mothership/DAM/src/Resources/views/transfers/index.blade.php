<x-admin::layouts>
    <x-slot:title>@lang('dam::transfers.title')</x-slot:title>
    <div class="flex flex-col gap-4">
        <x-admin::layouts.page-header :title="trans('dam::transfers.title')" :description="trans('dam::transfers.description')" :breadcrumb="false" />
        @include('dam::transfers.runtime', ['compact' => false])
    </div>
</x-admin::layouts>
