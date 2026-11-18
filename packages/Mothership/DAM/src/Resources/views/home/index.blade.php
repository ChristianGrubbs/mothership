<x-admin::layouts>
    <x-slot:title>Home — {{ config('documentation.name') }}</x-slot:title>
    <div v-pre data-mothership-home data-base="{{ url(config('app.admin_url').'/home') }}" data-csrf="{{ csrf_token() }}" data-user="{{ auth('admin')->id() }}" data-name="{{ auth('admin')->user()->name }}" data-library="{{ route('admin.dam.assets.index') }}" data-upload="{{ route('admin.dam.assets.upload') }}" data-directory-search="{{ route('admin.dam.directory.search') }}" data-directories="{{ route('admin.dam.directory.index') }}" data-library-allowed="{{ bouncer()->hasPermission('dam.assets') ? '1' : '0' }}" data-superuser="{{ auth('admin')->user()?->role?->permission_type === 'all' ? '1' : '0' }}">
        <header class="agh-hero">
            <p class="agh-hero-date" data-today></p>
            <h1 data-greeting>Welcome, {{ \Illuminate\Support\Str::before(auth('admin')->user()->name, ' ') }}</h1>
            <p class="agh-hero-brief" data-brief>Find a file, start a task, or see what is coming up.</p>
            @if (bouncer()->hasPermission('dam.assets'))
                <form class="agh-search" action="{{ route('admin.dam.assets.index') }}" method="GET" accept-charset="UTF-8" role="search" aria-label="Search all files" data-no-ajax-nav>
                    <input type="hidden" name="search_submit" value="1">
                    <label for="agh-home-search">Search all files</label>
                    <div class="agh-search-row">
                        <input id="agh-home-search" name="search" type="search" maxlength="255" autocomplete="off" placeholder="Type a name, a tag, or what is in the picture" data-home-search>
                        <button type="submit" class="agh-button agh-primary">Search</button>
                    </div>
                </form>
            @endif
            <nav class="agh-start" aria-label="Start something">
                @foreach ($actions as $action)
                    @if (isset($action['url']))
                        <a class="agh-start-item" href="{{ $action['url'] }}" data-start-key="{{ $action['key'] }}">
                    @else
                        <button type="button" class="agh-start-item" data-start-key="{{ $action['key'] }}" @isset($action['start']) data-start="{{ $action['start'] }}" @endisset @isset($action['url_fallback']) data-start-url="{{ $action['url_fallback'] }}" @endisset @isset($action['open']) data-start-open="{{ $action['open'] }}" @endisset>
                    @endif
                        <span class="agh-start-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="{{ $action['icon'] }}"/></svg></span>
                        <span class="agh-start-text"><strong>{{ $action['label'] }}</strong><small>{{ $action['help'] }}</small></span>
                    @if (isset($action['url']))
                        </a>
                    @else
                        </button>
                    @endif
                @endforeach
            </nav>
        </header>
        <div role="status" aria-live="polite" class="agh-status" data-status>Loading your Home…</div>
        <div class="agh-sections" data-grid>
            <div class="agh-day">
                <section class="agh-section" aria-labelledby="agh-day-title" data-section="day" hidden>
                    <h2 id="agh-day-title">Your day</h2>
                    <div class="agh-agenda" data-day data-id="calendar"></div>
                </section>
                <div class="agh-side">
                    <section class="agh-section agh-apps" aria-labelledby="agh-apps-title" data-section="apps">
                        <h2 id="agh-apps-title">Apps</h2>
                        <div class="agh-section-head agh-apps-head">
                            <h3 id="agh-tools-title">Studio tools</h3>
                            @if (filled($iconLibraryUrl = config('dam-home.icon_library_url')))<a href="{{ rtrim($iconLibraryUrl, '/') }}/" target="_blank" rel="noopener noreferrer">Icon Library <span aria-hidden="true">↗</span></a>@endif
                        </div>
                        <ul class="agh-app-grid" aria-labelledby="agh-tools-title">
                            <li data-tools-end><button type="button" class="agh-app" data-id="phone-app" data-phone-open title="Put The Mothership on your iPhone or iPad">
                                <span class="agh-app-icon agh-app-phone" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 2.5h8a1.5 1.5 0 0 1 1.5 1.5v16a1.5 1.5 0 0 1-1.5 1.5H8A1.5 1.5 0 0 1 6.500 20V4A1.5 1.5 0 0 1 8 2.500z M10.500 5h3 M12 12v5 M9.500 14.500L12 17l2.500-2.500"/></svg></span>
                                <strong>iPhone app</strong>
                                <small>Get The Mothership on your phone</small>
                            </button></li>
                        </ul>
                    </section>
                    @if ($transfers ?? null)
                        <section class="agh-section agh-feature" aria-labelledby="agh-send-title" data-block="send" data-id="transfers">
                            <div class="agh-section-head">
                                <h2 id="agh-send-title">Send files to a person</h2>
                                <a href="{{ $transfers['transfersUrl'] }}" data-send-open>Open Transfers <span aria-hidden="true">›</span></a>
                            </div>
                            <p class="agh-section-note">Files go from your browser to theirs, directly where the network allows and through The Mothership where it does not. Nothing is stored in between, so both of you need The Mothership open.</p>
                            <div class="agh-send" data-send>
                                <p class="agh-caption" role="status">Looking for people who are online…</p>
                            </div>
                        </section>
                    @endif
                </div>
            </div>
            <section class="agh-section" aria-labelledby="agh-recent-title" data-section="recent" hidden>
                <div class="agh-section-head">
                    <h2 id="agh-recent-title">Recent uploads</h2>
                    <a href="{{ route('admin.dam.index') }}">See all files <span aria-hidden="true">›</span></a>
                </div>
                <div class="agh-work" data-recent data-id="recent"></div>
                <div class="agh-starred" data-starred data-id="favorites"></div>
            </section>
            @if (auth('admin')->user()?->role?->permission_type === 'all')
                <section class="agh-section agh-admin" aria-labelledby="agh-status-title" data-section="status" hidden>
                    <div class="agh-section-head">
                        <h2 id="agh-status-title">Studio admin</h2>
                        <span class="agh-admin-note">Only administrators see this part of Home.</span>
                    </div>
                    <div class="agh-slot agh-around" data-slot="status"></div>
                </section>
            @endif
            <section class="agh-section" aria-labelledby="agh-links-title" data-section="links" hidden>
                <h2 id="agh-links-title">Your saved links</h2>
                <div class="agh-saved-links" data-saved-links></div>
            </section>
        </div>
        <dialog class="agh-settings agh-calendar-dialog" data-calendar-dialog aria-labelledby="agh-calendar-title">
            <div class="agh-dialog-head"><h2 id="agh-calendar-title">Calendar</h2><button type="button" class="agh-button" data-calendar-close aria-label="Close calendar">✕</button></div>
            <div class="agh-slot" data-slot="coming"></div>
        </dialog>
        <dialog class="agh-settings agh-upload" data-upload-dialog aria-labelledby="agh-upload-title"></dialog>
        <dialog class="agh-settings agh-phone" data-phone-dialog aria-labelledby="agh-phone-title">
            <div class="agh-dialog-head"><h2 id="agh-phone-title">The Mothership on your iPhone</h2><button type="button" class="agh-button" data-phone-close aria-label="Close">✕</button></div>
            <div class="agh-phone-body">
                <div>
                    <p class="agh-section-note">There is nothing to download from the App Store. Add The Mothership to your Home Screen and it opens like any other app, with your files, the calendar and upload from your camera roll.</p>
                    <ol class="agh-phone-steps">
                        <li>On your iPhone or iPad, open <b>{{ $phoneHost }}</b> in Safari and sign in.</li>
                        <li>Tap <b>Share</b> <span class="agh-phone-share" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 15V3 M8 7l4-4 4 4 M6 11H5v10h14V11h-1"/></svg></span> at the bottom of the screen.</li>
                        <li>Tap <b>Add to Home Screen</b>, then <b>Add</b>.</li>
                    </ol>
                    <p class="agh-section-note agh-phone-foot">On an Android phone, open the same address in Chrome and choose <b>Install app</b> from the menu.</p>
                </div>
                <div class="agh-phone-art" aria-hidden="true"><span class="agh-phone-app"></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
            </div>
        </dialog>
    </div>
    @if ($transfers ?? null)
        @include('dam::transfers.runtime', ['compact' => true])
    @endif
    @push('scripts')
        {{-- AJAX navigation carries body styles along with this page's scripts. --}}
        <style>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/css/home.css')) !!}</style>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home-state.js')) !!}</script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home-system-quickview.js')) !!}</script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home-calendar.js')) !!}</script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home.js')) !!}</script>
    @endpush
</x-admin::layouts>
