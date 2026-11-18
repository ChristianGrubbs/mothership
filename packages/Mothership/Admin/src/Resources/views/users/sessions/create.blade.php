<x-admin::layouts.anonymous>
    <x-slot:title>
        @lang('admin::app.users.sessions.title')
    </x-slot>

    <x-slot:background>
        <div class="bridge-signin-hero" aria-hidden="true" inert data-login-backdrop>
            <img src="{{ mothership_asset('images/brand/hero-colour-dark.svg') }}" width="300" height="300" alt="">

            <x-admin::bridge.board-strip :text="trans('admin::app.users.sessions.board')" :cells="14" />
        </div>
    </x-slot>

    {{-- The admin's stable scrollbar gutter would leave a bare strip beside the full-bleed hero. --}}
    @push('styles')
        <style>
            html {
                scrollbar-gutter: auto;
            }
        </style>
    @endpush

    {{--
        Bridge sign-in (redesign v2, Screens.html): the graphite hero panel with the brand hero and a
        static board strip on the left, the form on the ground on the right. The board does not flip
        here: split-flap motion belongs to Home's arrivals board only (motion v2-docking, rule 2).
    --}}
    <div class="bridge-signin-wrap bridge-signin-side relative z-10">
        <div class="bridge-signin-form">
            <div class="bridge-signin-word">
                @if ($logo = core()->getConfigData('general.design.admin_logo.logo_image'))
                    <img
                        class="h-8 w-auto"
                        src="{{ Storage::url($logo) }}"
                        data-mothership-logo
                        title="{{ config('documentation.name') }}"
                        alt="{{ config('documentation.name') }}"
                    />
                @else
                    <img
                        class="h-8 w-auto dark:hidden"
                        src="{{ mothership_asset('images/logo.svg') }}"
                        data-mothership-logo
                        title="{{ config('documentation.name') }}"
                        alt="{{ config('documentation.name') }}"
                    />

                    <img
                        class="h-8 w-auto hidden dark:block"
                        src="{{ mothership_asset('images/dark_logo.svg') }}"
                        data-mothership-logo
                        title="{{ config('documentation.name') }}"
                        alt="{{ config('documentation.name') }}"
                    />
                @endif
            </div>

            <div>
                <h1>@lang('admin::app.users.sessions.title')</h1>

                <p class="bridge-signin-sub">
                    @if ($passwordLogin ?? true)
                        @lang('admin::app.users.sessions.subtitle', ['name' => config('documentation.name')])
                    @else
                        @lang('admin::app.users.sessions.password-off')
                    @endif
                </p>

                @if ($passwordLogin ?? true)
                    <x-admin::form :action="route('admin.session.store')" :track-dirty="false" ajax="true">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                @lang('admin::app.users.sessions.email')
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="email"
                                class="w-full"
                                id="email"
                                name="email"
                                rules="required|email"
                                :value="old('email')"
                                :label="trans('admin::app.users.sessions.email')"
                                :placeholder="trans('admin::app.users.sessions.email')"
                                autocomplete="username"
                                autofocus
                            />

                            <x-admin::form.control-group.error control-name="email" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="relative w-full">
                            <x-admin::form.control-group.label>
                                @lang('admin::app.users.sessions.password')
                            </x-admin::form.control-group.label>

                            <x-admin::form.control-group.control
                                type="password"
                                class="w-full ltr:pr-11 rtl:pl-11"
                                id="password"
                                name="password"
                                rules="required"
                                :label="trans('admin::app.users.sessions.password')"
                                :placeholder="trans('admin::app.users.sessions.password')"
                                autocomplete="current-password"
                            />

                            <button
                                type="button"
                                class="bridge-signin-eye icon-view"
                                onclick="switchVisibility()"
                                id="visibilityIcon"
                                aria-controls="password"
                                aria-pressed="false"
                                aria-label="{{ trans('admin::app.users.sessions.toggle-password') }}"
                            >
                            </button>

                            <x-admin::form.control-group.error control-name="password" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="bridge-signin-remember !mb-0">
                            <x-admin::form.control-group.control
                                type="checkbox"
                                id="remember"
                                name="remember"
                                value="1"
                                for="remember"
                            />

                            <label for="remember">
                                @lang('admin::app.users.sessions.remember-me')
                            </label>
                        </x-admin::form.control-group>

                        <button
                            type="submit"
                            class="bridge-btn-act bridge-btn-wide"
                            aria-label="{{ trans('admin::app.users.sessions.submit-btn') }}"
                        >
                            @lang('admin::app.users.sessions.submit-btn')
                        </button>

                        <div class="bridge-signin-links">
                            <a href="{{ route('admin.forget_password.create') }}">@lang('admin::app.users.sessions.forget-password-link')</a>
                            <a href="{{ route('admin.invite.accept.create') }}">@lang('admin::app.users.sessions.invite-code-link')</a>
                        </div>
                    </x-admin::form>
                @endif

                <x-admin::sso.buttons :providers="$ssoProviders ?? collect()" :divider="$passwordLogin ?? true" />
            </div>

        </div>
    </div>

    @push('scripts')
        <script>
            (() => {
                // Leave this page with a full load, never an ajax visit: a visit swaps only #app, so the
                // video backdrop and this page's <head> would otherwise stay behind the admin after sign-in.
                const leaveWithFullLoad = (url) => {
                    window.location.assign(url);

                    return false;
                };

                if (window.mothership?.registerNavigationGuard) {
                    window.mothership.registerNavigationGuard(leaveWithFullLoad);
                } else {
                    (window.__mothershipNavGuardQueue ||= []).push(leaveWithFullLoad);
                }
            })();

            function switchVisibility() {
                let passwordField = document.getElementById("password");
                let visibilityIcon = document.getElementById("visibilityIcon");

                passwordField.type = passwordField.type === "password" ? "text" : "password";

                visibilityIcon.setAttribute("aria-pressed", passwordField.type === "text" ? "true" : "false");

                visibilityIcon.classList.toggle("icon-view");
                visibilityIcon.classList.toggle("icon-view-close");
            }

            document.addEventListener("input", function (event) {
                const field = event.target;

                if (field.id !== "password" || ! /^\s/.test(field.value)) {
                    return;
                }

                const setValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set;

                setValue.call(field, field.value.replace(/^\s+/, ""));

                field.dispatchEvent(new Event("input", { bubbles: true }));
            });
        </script>
    @endpush
</x-admin::layouts.anonymous>
