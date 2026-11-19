<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.dam.tools.title')
    </x-slot:title>

    <x-admin::layouts.page-header
        :breadcrumb="false"
        :title="trans('dam::app.admin.dam.tools.title')"
        :description="trans('dam::app.admin.dam.tools.description')"
    />

    {{-- One tile per tool (ToolsController::index). A tile is one link; a tool this install has not set up is shown
         to full admins only, as a tile that is not a link. The grid follows this block's own width (app.css, "Tools");
         on a folded screen it puts one column of tiles on each side of the fold, or in tabletop the first row above the
         fold and the rest below it (public/pwa/device.css). --}}
    <div class="bridge-tools" data-tools-page>
        <ul class="bridge-tools-grid" data-fold-split>
            @foreach ($tiles as $tile)
                <li>
                    @if ($tile['url'])
                        {{-- A full page load: the tool frame is its own document, without the rail. --}}
                        <a href="{{ $tile['url'] }}" class="bridge-tool-tile" data-tool-tile="{{ $tile['id'] }}" data-no-ajax-nav>
                    @else
                        <div class="bridge-tool-tile is-setup" data-tool-tile="{{ $tile['id'] }}" data-tool-setup>
                    @endif
                        <span class="bridge-tool-well"><x-admin::bridge.icon :name="$tile['glyph']" :size="20" /></span>

                        <span class="bridge-tool-text">
                            <span class="bridge-tool-name">{{ $tile['title'] }}</span>
                            <span class="bridge-tool-line">{{ $tile['setup'] ?? $tile['description'] }}</span>
                        </span>

                        <span class="bridge-tool-foot bridge-mono" data-tool-standing>
                            @if ($tile['lamp'])
                                <i class="bridge-lamp is-{{ $tile['lamp'] }}" aria-hidden="true"></i>
                            @endif
                            {{ $tile['status'] }}
                        </span>
                    @if ($tile['url'])
                        </a>
                    @else
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</x-admin::layouts>
