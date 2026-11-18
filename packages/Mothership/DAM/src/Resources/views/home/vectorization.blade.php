<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Image Vectorization</title>
    {!! app(\Mothership\DAM\Http\Controllers\HomeToolController::class)->bootstrap('vectorization') !!}
    <link rel="stylesheet" href="{{ route('admin.home.tool',['widget'=>'vectorization','path'=>'style.css']) }}">
</head>
<body>
<main id="vectorization" data-state="{{ route('admin.home.vectorization.state') }}"
    data-source="{{ route('admin.dam.assets.custom_download', '__ASSET__') }}"
    data-asset="{{ route('admin.dam.assets.show', '__ASSET__') }}"
    data-submit="{{ route('admin.home.vectorization.store') }}" data-csrf="{{ csrf_token() }}">
    <header><h1>Image Vectorization</h1><p>Turn an image into editable vectors for Adobe and AI tools.</p><strong data-balance>Loading balance…</strong></header>
    <section class="upload">
        <label for="vector-input">Choose an image</label>
        <input id="vector-input" data-input type="file" accept=".png,.jpg,.jpeg,.webp,.bmp,.gif,.tif,.tiff">
        <p>PNG, JPEG, WebP, BMP, GIF or TIFF · up to 20 MiB. One completed conversion uses one vectorization.</p>
        <button data-submit-button disabled>Vectorize image</button>
        <p data-status role="status" aria-live="polite"></p>
    </section>
    <section data-result hidden>
        <div class="actions"><a data-svg download>Download SVG</a><a data-pdf download>Download PDF</a><button data-copy disabled>Copy SVG code</button>
            <label>Zoom <input data-zoom type="range" min="25" max="400" step="25" value="100"></label><output data-zoom-value>100%</output></div>
        <div class="previews"><figure><figcaption>Original</figcaption><div class="canvas"><img data-original alt="Original image" hidden></div></figure>
            <figure><figcaption>Vector result</figcaption><div class="canvas"><img data-preview alt="Vectorized result"></div></figure></div>
        <p>Open the SVG in Adobe, or attach it to your preferred AI chat. Copy SVG code when your chat accepts text.</p>
        <label data-copy-fallback hidden>SVG code — select and copy<textarea data-code readonly spellcheck="false"></textarea></label>
    </section>
    <section><h2>Your recent vectorizations</h2><div data-history></div></section>
</main>
<script src="{{ route('admin.home.tool',['widget'=>'vectorization','path'=>'app.js']) }}"></script>
</body></html>
