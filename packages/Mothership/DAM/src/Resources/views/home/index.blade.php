<x-admin::layouts>
    <x-slot:title>Home — {{ config('documentation.name') }}</x-slot:title>
    @php
        $homeLinks = [
            'connect' => bouncer()->hasPermission('configuration.integrations') && \Illuminate\Support\Facades\Route::has('admin.configuration.integrations.index') ? route('admin.configuration.integrations.index') : '',
            'shares' => bouncer()->hasPermission('dam.shares') ? route('admin.dam.shared-links.index') : '',
            'requests' => bouncer()->hasPermission('dam.upload_requests') ? route('admin.dam.upload-requests.index') : '',
            'newUploads' => bouncer()->hasPermission('dam') ? route('admin.dam.new-uploads.index') : '',
        ];
    @endphp
    <div v-pre data-mothership-home data-base="{{ url(config('app.admin_url').'/home') }}" data-csrf="{{ csrf_token() }}" data-user="{{ auth('admin')->id() }}" data-name="{{ auth('admin')->user()->name }}" data-library="{{ route('admin.dam.assets.index') }}" data-upload="{{ route('admin.dam.assets.upload') }}" data-directory-search="{{ route('admin.dam.directory.search') }}" data-directories="{{ route('admin.dam.directory.index') }}" data-library-allowed="{{ bouncer()->hasPermission('dam.assets') ? '1' : '0' }}" data-superuser="{{ auth('admin')->user()?->role?->permission_type === 'all' ? '1' : '0' }}" data-connect="{{ $homeLinks['connect'] }}" data-shares="{{ $homeLinks['shares'] }}" data-requests="{{ $homeLinks['requests'] }}" data-new-uploads="{{ $homeLinks['newUploads'] }}">
        <h1 class="hm-sr">Home</h1>
        <div role="status" aria-live="polite" class="hm-status" data-status></div>
        <div role="status" aria-live="polite" class="hm-stale" data-stale></div>
        <div class="hm-layout" data-grid>
            <div class="hm-centre">
                <div class="hm-object" data-board-object>
                    <section class="hm-board" data-m="board" data-board aria-labelledby="hm-board-title" aria-busy="true">
                        <div class="hm-board-head hm-mono">
                            <h2 id="hm-board-title">Arrivals board</h2>
                            <span class="hm-sp"></span>
                            <span class="hm-board-state" data-board-state></span>
                        </div>
                        <div class="hm-cols hm-mono" aria-hidden="true">
                            <span class="k1" style="--n:6">Time</span>
                            <span class="k2" style="--n:12">From</span>
                            <span class="k3" style="--n:21">Cargo</span>
                            <span class="k4" style="--n:4">Qty</span>
                            <span class="k5" style="--n:8">Status</span>
                        </div>
                        <div role="list" aria-label="Arrivals since your last visit" data-board-rows></div>
                        <p class="hm-more hm-mono" data-board-more hidden></p>
                    </section>
                    <section class="hm-deck" data-m="arrive" data-deck aria-label="New since your last visit" aria-busy="true"></section>
                </div>

                <nav class="hm-card hm-glance" aria-label="Around the ship" data-glance></nav>

                <section class="hm-card hm-needs" data-m="panel" data-needs aria-label="Needs you" hidden></section>

                {{-- Shown while it has rows or offers Calendar: an account with no files yet still gets the calendar here. --}}
                <div data-cargo-section hidden>
                    <div class="hm-sec" id="hm-cargo">
                        <h2>Cargo</h2>
                        <span class="hm-mono">What landed, newest first</span>
                        {{-- One group, so a narrow head wraps Calendar and Open in Library together. --}}
                        <span class="hm-sec-acts">
                            <button type="button" class="hm-sec-link is-quiet" data-calendar-open hidden><svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v14H4z M4 10h16 M8 3v5 M16 3v5"/></svg>Calendar</button>
                            @if (bouncer()->hasPermission('dam.assets'))
                                <a class="hm-sec-link" href="{{ route('admin.dam.assets.index') }}">Open in Library <svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h10v10M7 17 17 7"/></svg></a>
                            @endif
                        </span>
                    </div>
                    <ol class="hm-log" data-m="arrive" data-cargo></ol>
                    <p class="hm-empty hm-cargo-empty" data-cargo-empty hidden>Nothing has landed in your Library yet.</p>
                </div>
            </div>

            {{-- The summary paints each card's body; its foot is Home's own and stays through every repaint. --}}
            <aside class="hm-side" aria-label="Around the ship" data-side>
                <section class="hm-card hm-dock" id="hm-docked" data-docked>
                    <div data-docked-body></div>
                    <p class="hm-foot"><button type="button" class="hm-foot-link" data-id="phone-app" data-phone-open><svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2.500h8A1.500 1.500 0 0 1 17.500 4v16a1.500 1.500 0 0 1-1.500 1.500H8A1.500 1.500 0 0 1 6.500 20V4A1.500 1.500 0 0 1 8 2.500z M11 18.500h2"/></svg>Add your iPhone</button></p>
                </section>
                <section class="hm-card hm-signal" id="hm-signal" data-signal data-id="rss"></section>
                <section class="hm-card hm-away" id="hm-away" data-away>
                    <div data-away-body></div>
                    <div class="hm-foot" data-section="links" hidden>
                        <h3 class="hm-mono">Your saved links</h3>
                        <div class="hm-saved-links" data-saved-links></div>
                    </div>
                    {{-- A role without the Library has no Docked card, so its Add your iPhone sits here. --}}
                    <p class="hm-foot" data-phone-elsewhere hidden><button type="button" class="hm-foot-link" data-id="phone-app" data-phone-open><svg class="hm-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 2.500h8A1.500 1.500 0 0 1 17.500 4v16a1.500 1.500 0 0 1-1.500 1.500H8A1.500 1.500 0 0 1 6.500 20V4A1.500 1.500 0 0 1 8 2.500z M11 18.500h2"/></svg>Add your iPhone</button></p>
                </section>
            </aside>
        </div>

        <dialog class="hm-sheet" data-side-sheet aria-labelledby="hm-sheet-title">
            <div class="hm-sheet-head"><h2 id="hm-sheet-title">Around the ship</h2><button type="button" class="hm-btn" data-sheet-close>Close</button></div>
            <div data-sheet-body></div>
        </dialog>

        <dialog class="agh-settings agh-calendar-dialog" data-calendar-dialog aria-labelledby="agh-calendar-title">
            <div class="agh-dialog-head"><h2 id="agh-calendar-title">Calendar</h2><button type="button" class="agh-button" data-calendar-close aria-label="Close calendar">✕</button></div>
            <div class="agh-slot" data-slot="coming"><article class="agh-card" data-id="calendar"><div class="agh-card-body"></div></article></div>
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
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home-calendar.js')) !!}</script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home-signal.js')) !!}</script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home-bridge.js')) !!}</script>
        <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/home.js')) !!}</script>
    @endpush
</x-admin::layouts>
