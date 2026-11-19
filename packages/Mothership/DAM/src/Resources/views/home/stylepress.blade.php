<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>StylePress</title>
    {!! app(\Mothership\DAM\Http\Controllers\HomeToolController::class)->bootstrap('stylepress') !!}
    <link rel="stylesheet" href="{{ route('admin.home.tool', ['widget' => '_kit', 'path' => 'tool-kit.css']) }}">
    <link rel="stylesheet" href="{{ route('admin.home.tool', ['widget' => 'stylepress', 'path' => 'style.css']) }}">
</head>
<body>
{{-- The page's script (widgets/stylepress/app.js) reads every address from these data attributes. --}}
<main class="sp tk-panes" id="stylepress" data-connected="{{ $generationAvailable ? '1' : '0' }}" data-csrf="{{ csrf_token() }}"
      data-generate-url="{{ $generateUrl }}" data-estimate-url="{{ $estimateUrl }}" data-balance-url="{{ $balanceUrl }}"
      data-enhance-url="{{ route('admin.home.stylepress.enhance') }}" data-reference-url="{{ route('admin.home.stylepress.reference') }}"
      data-folders-url="{{ route('admin.home.stylepress.folders') }}" data-assets-url="{{ route('admin.home.stylepress.assets') }}"
      data-thumbnail-base="{{ url('admin/home/stylepress/thumbnail') }}" data-source-base="{{ url('admin/home/stylepress/source') }}"
      data-library-base="{{ url('admin/dam/assets') }}" data-app-name="{{ config('documentation.name') }}">
    <section class="tk-pane sp-styles" id="sp-styles" aria-labelledby="sp-styles-title">
        <div class="tk-head sp-styles-head"><h2 id="sp-styles-title">Styles</h2><span class="sp-head-end"><button id="sp-clear" class="tk-link" type="button" hidden>Clear filters</button><span id="sp-visible" class="tk-mono" aria-live="polite"></span></span></div>
        {{-- Each select says what it holds in its own options, so the names are for screen readers only. --}}
        <div class="sp-find">
            <label class="sp-search"><span class="tk-hidden">Search styles</span><input id="sp-search" class="tk-input" type="search" placeholder="Search styles and slots" autocomplete="off"></label>
            <label class="sp-filter sp-filter-cat"><span class="tk-hidden">Category</span><select id="sp-category" class="tk-select"></select></label>
            <label class="sp-filter"><span class="tk-hidden">Aspect ratio</span><select id="sp-aspect" class="tk-select"><option value="all">All ratios</option><option>1:1</option><option>2:3</option><option>3:2</option><option>3:4</option><option>4:3</option><option>4:5</option><option>16:9</option><option>9:16</option><option>21:9</option></select></label>
            <label class="sp-filter"><span class="tk-hidden">Sort</span><select id="sp-sort" class="tk-select"><option value="relevance">Sort: Relevance</option><option value="title">Sort: A to Z</option><option value="recent">Sort: Recent</option><option value="favorites">Sort: Favorites</option></select></label>
        </div>
        <div class="sp-view">
            <div class="tk-seg sp-tabs" role="group" aria-label="Show">
                <button class="tk-seg-opt" type="button" aria-pressed="true" data-tab="all">All</button>
                <button class="tk-seg-opt" type="button" aria-pressed="false" data-tab="favorites">Favorites</button>
                <button class="tk-seg-opt" type="button" aria-pressed="false" data-tab="recent">Recent</button>
                <button class="tk-seg-opt" type="button" aria-pressed="false" data-tab="needs-slots">Needs slots</button>
            </div>
            <div class="sp-view-end">
                <label class="tk-check sp-group-check"><input id="sp-group" type="checkbox"> Group by category</label>
                <div class="tk-seg sp-density" role="group" aria-label="Card size">
                    <button class="tk-seg-opt" type="button" aria-pressed="true" data-density="comfortable" aria-label="Large cards" title="Large cards"><svg class="tk-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg></button>
                    <button class="tk-seg-opt" type="button" aria-pressed="false" data-density="compact" aria-label="Compact cards" title="Compact cards"><svg class="tk-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h4v4H4zM10 4h4v4h-4zM16 4h4v4h-4zM4 10h4v4H4zM10 10h4v4h-4zM16 10h4v4h-4zM4 16h4v4H4zM10 16h4v4h-4zM16 16h4v4h-4z"/></svg></button>
                    <button class="tk-seg-opt" type="button" aria-pressed="false" data-density="list" aria-label="List" title="List"><svg class="tk-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="4" height="4" rx="1"/><rect x="4" y="15" width="4" height="4" rx="1"/><path d="M11 7h9M11 17h9"/></svg></button>
                </div>
            </div>
        </div>
        <div id="sp-cards" class="sp-cards"></div>
        <p id="sp-empty" class="tk-empty" hidden></p>
    </section>

    <section class="tk-pane sp-make" id="sp-make" aria-label="Make an image">
        <div id="sp-output" class="sp-output" hidden></div>
        <div class="sp-chosen">
            <div class="sp-preview"><img id="sp-preview" alt=""><span id="sp-preview-none" hidden>Reference entry</span></div>
            <div class="sp-chosen-text">
                <div id="sp-cat" class="tk-mono sp-cat"></div>
                <h2 id="sp-title" class="sp-title"></h2>
                <button id="sp-change-style" class="tk-btn is-small sp-change" type="button" aria-haspopup="dialog">Change style</button>
            </div>
        </div>
        <div class="sp-about">
            <p id="sp-look" class="sp-look"></p>
            <button id="sp-look-more" class="tk-link sp-more" type="button" aria-expanded="false" hidden>More</button>
            <details id="sp-sources" class="sp-sources" hidden><summary>Source note and style pack</summary><div id="sp-source-files" class="sp-source-files"></div></details>
            <div class="tk-head">Slot contract</div>
            <div id="sp-slots" class="sp-slots"></div>
        </div>

        <div class="sp-block">
            <label class="tk-field" for="sp-prompt">Your request</label>
            <textarea id="sp-prompt" class="tk-input sp-textarea" placeholder="Describe the asset, campaign, brand, product or utility you want to make"></textarea>
            <div class="sp-request-foot">
                <p id="sp-prompt-count" class="tk-mono sp-count"></p>
                <button id="sp-enhance" class="tk-btn is-small" type="button">Enhance request</button>
            </div>
        </div>

        <div id="sp-enhancement" class="sp-block sp-enhancement" hidden>
            <label class="tk-field" for="sp-enhanced">Enhanced request</label>
            <textarea id="sp-enhanced" class="tk-input sp-textarea"></textarea>
            <p id="sp-enhancement-state" class="tk-note"></p>
            <div class="sp-row">
                <button id="sp-reenhance" class="tk-btn is-small" type="button" hidden>Enhance again</button>
                <button id="sp-accept-enhanced" class="tk-btn is-small" type="button">Use this text</button>
                <button id="sp-discard-enhanced" class="tk-btn is-small" type="button">Discard</button>
            </div>
            <p class="tk-note">Enhance reads your references and a summary of the style. Generate applies the whole style template.</p>
        </div>

        <div class="sp-block">
            <input id="sp-mode" type="hidden" value="create">
            <div class="tk-seg sp-mode" role="group" aria-label="Mode">
                <button class="tk-seg-opt" type="button" aria-pressed="true" data-mode="create">Create new</button>
                <button class="tk-seg-opt" type="button" aria-pressed="false" data-mode="restyle">Restyle a source</button>
            </div>
            <p id="sp-mode-note" class="tk-note" hidden>Restyle needs a source image. Add one under References.</p>
        </div>

        <div class="sp-block">
            <div class="tk-head">References <span id="sp-ref-count" class="tk-mono">0/8</span></div>
            <div class="sp-ref-actions">
                <label class="tk-btn is-small sp-upload"><input id="sp-upload" class="tk-hidden" type="file" accept="image/png,image/jpeg,image/webp" multiple>Upload</label>
                <button id="sp-browse-assets" class="tk-btn is-small" type="button" aria-haspopup="dialog">From {{ config('documentation.name') }}</button>
            </div>
            <div id="sp-references" class="sp-references"></div>
        </div>

        <div class="sp-block">
            <div class="tk-head">Settings</div>
            <div class="sp-settings">
                <label class="tk-field">Output ratio<select id="sp-output-aspect" class="tk-select"><option value="">Style default</option><option>auto</option><option>1:1</option><option>4:3</option><option>3:4</option><option>16:9</option><option>21:9</option><option>9:16</option><option>3:2</option><option>2:3</option></select></label>
                <label class="tk-field">Model<select id="sp-model" class="tk-select"><option value="gpt_image_2_5:flare" selected>GPT Image 2.5 · Flare</option><option value="gpt_image_2_5:sunburst">GPT Image 2.5 · Sunburst</option><option value="gpt_image_2">GPT Image 2</option></select></label>
                <label class="tk-field">Quality<select id="sp-quality" class="tk-select"><option value="low">Low</option><option value="medium">Medium</option><option value="high" selected>High</option></select></label>
                <label class="tk-field">Resolution<select id="sp-resolution" class="tk-select"><option>1k</option><option selected>2k</option><option>4k</option></select></label>
            </div>
        </div>

        <div class="sp-actions">
            <div class="sp-row">
                <button id="sp-estimate" class="tk-btn is-small" type="button">Estimate credits</button>
                <button id="sp-copy" class="tk-btn is-small" type="button">Copy prompt</button>
            </div>
            <button id="sp-generate" class="tk-btn is-act sp-generate" type="button">Generate</button>
            <p id="sp-result" class="tk-status sp-result" role="status" hidden></p>
            <p id="sp-balance" class="tk-mono sp-balance">Reading the shared balance…</p>
        </div>
    </section>
</main>

<dialog id="sp-browse" class="tk-sheet sp-browse" aria-labelledby="sp-browse-title">
    <div class="tk-sheet-head"><h2 id="sp-browse-title">Choose a style</h2><button id="sp-browse-done" class="tk-btn is-small" type="button">Done</button></div>
    <div class="sp-browse-body" id="sp-browse-body"></div>
</dialog>

<dialog id="sp-asset-dialog" class="tk-sheet sp-picker" aria-labelledby="sp-asset-heading">
    <div class="tk-sheet-head"><h2 id="sp-asset-heading">Add from {{ config('documentation.name') }}</h2><button id="sp-asset-close" class="tk-btn is-quiet" type="button" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
    <div class="sp-lib">
        <nav id="sp-lib-tree" class="sp-lib-tree" aria-label="Library folders">
            <div class="sp-lib-section">Library</div>
            <button class="sp-lib-entry active" data-kind="all" type="button"><span class="sp-lib-toggle leaf"></span><span class="sp-lib-name">All images</span></button>
            <button class="sp-lib-entry" data-kind="recent" type="button"><span class="sp-lib-toggle leaf"></span><span class="sp-lib-name">Added in the last 30 days</span></button>
            <button class="sp-lib-entry" data-kind="favorites" type="button"><span class="sp-lib-toggle leaf"></span><span class="sp-lib-name">Favorites</span></button>
            <div class="sp-lib-section">Folders</div>
            <div id="sp-lib-folders"><p class="sp-asset-status sp-lib-note">Loading folders…</p></div>
        </nav>
        <div class="sp-lib-main">
            <div class="sp-asset-tools"><input id="sp-asset-search" class="tk-input" type="search" placeholder="Search by name, title or tag" autocomplete="off" aria-label="Search images"><select id="sp-asset-sort" class="tk-select" aria-label="Sort"><option value="newest">Newest first</option><option value="name">Name A to Z</option></select></div>
            <div id="sp-asset-crumbs" class="sp-asset-crumbs"></div>
            <p id="sp-asset-status" class="sp-asset-status" aria-live="polite"></p>
            <div id="sp-asset-results" class="sp-asset-grid" role="listbox" aria-multiselectable="true" aria-label="Images"></div>
        </div>
    </div>
    <div class="tk-sheet-foot"><span id="sp-asset-selection" class="tk-status">Choose images to add.</span><button id="sp-asset-cancel" class="tk-btn" type="button">Cancel</button><button id="sp-asset-add" class="tk-btn is-act" type="button" disabled>Add selected</button></div>
</dialog>

<dialog id="sp-lightbox" class="sp-lightbox" aria-label="Generated image">
    <div class="sp-lightbox-panel"><img id="sp-lightbox-image" alt="Generated image"><div class="sp-lightbox-bar"><a id="sp-lightbox-open" class="tk-btn" target="_blank" rel="noopener noreferrer">Open in new tab</a><button id="sp-lightbox-close" class="tk-btn" type="button">Close</button></div></div>
</dialog>

<script type="application/json" id="sp-catalog">{!! json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
<script src="{{ route('admin.home.tool', ['widget' => 'stylepress', 'path' => 'app.js']) }}"></script>
</body>
</html>
