<x-admin::layouts.anonymous>
    <!-- Page Title -->
    <x-slot:title>
        @lang("admin::app.errors.{$errorCode}.title")
    </x-slot>

    @php
        $supportEmail = \Mothership\Admin\Helpers\SupportContact::email();
    @endphp

    <!-- Error page Information -->
	<div class="flex justify-center items-center h-[100vh] px-4 bg-[var(--ground)] dark:bg-cherry-800">
        <div class="flex gap-5 items-center max-w-[900px]">
            <div class="w-full">
                <img
                    src="{{ mothership_asset('images/logo.svg') }}"
                    class="mb-6 h-8 w-auto dark:hidden"
                    alt="{{ config('app.name') }}"
                >

                <img
                    src="{{ mothership_asset('images/dark_logo.svg') }}"
                    class="mb-6 h-8 w-auto hidden dark:block"
                    alt=""
                >

				<div class="text-[38px] text-gray-800 dark:text-white font-bold">
                    @lang("admin::app.errors.{$errorCode}.title")
                </div>

                <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
                    {{ $description ?? trans("admin::app.errors.{$errorCode}.description") }}
                </p>

                <div class="mb-6">
                    <div class="flex gap-2.5 items-center">
                        <button
                            type="button"
                            onclick="history.back()"
                            class="text-sm text-primary-700 font-semibold transition-all hover:underline cursor-pointer"
                        >
                            @lang('admin::app.errors.go-back')
                        </button>

                        <span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="6" height="7" viewBox="0 0 6 7" fill="none">
                                <circle cx="3" cy="3.5" r="3" fill="#9CA3AF"/>
                            </svg>
                        </span>

                        <a
                            href="{{ route('admin.home.index') }}"
                            class="text-sm text-primary-700 font-semibold transition-all hover:underline"
                        >
                            @lang('admin::app.errors.dashboard')
                        </a>
                    </div>
                </div>

                @if ($supportEmail)
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                    @lang('admin::app.errors.support', [
                        'link'  => 'mailto:' . $supportEmail,
                        'email' => $supportEmail,
                        'class' => 'text-primary-700 font-semibold transition-all hover:underline',
                        ])
                    </p>
                @endif
            </div>

            <div class="w-full">
                <img src="{{ mothership_asset('images/error.svg') }}" alt="@lang("admin::app.errors.{$errorCode}.title")" />
            </div>
        </div>
	</div>
</x-admin::layouts.anonymous>