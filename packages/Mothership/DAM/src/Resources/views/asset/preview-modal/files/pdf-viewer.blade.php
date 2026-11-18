<div ref="pdfHost" data-dam-pdf-viewer class="w-full h-full min-h-0" style="display:flex;flex-direction:column;min-width:0;">
    <div data-pdf-toolbar role="toolbar" aria-label="PDF controls" class="flex flex-wrap items-center gap-2 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-2 text-sm text-gray-900 dark:text-gray-200">
        <button type="button" data-pdf-fit-width aria-pressed="true" class="secondary-button">Fit width</button>
        <button type="button" data-pdf-fit-page aria-pressed="false" class="secondary-button">Fit page</button>
        <button type="button" data-pdf-zoom-out aria-label="Zoom out" class="secondary-button">−</button>
        <output data-pdf-zoom class="min-w-12 text-center tabular-nums">100%</output>
        <button type="button" data-pdf-zoom-in aria-label="Zoom in" class="secondary-button">+</button>
        <button type="button" data-pdf-previous aria-label="Previous page" class="secondary-button">‹</button>
        <label class="flex items-center gap-1">Page <input data-pdf-page-number type="number" min="1" value="1" aria-label="Page number" class="w-16 rounded border border-gray-300 dark:border-gray-600 bg-transparent p-1" /></label>
        <span>/ <span data-pdf-count>—</span></span>
        <button type="button" data-pdf-next aria-label="Next page" class="secondary-button">›</button>
        <a data-pdf-original :href="previewData.mediaUrl" target="_blank" rel="noopener" class="ml-auto underline">Open original PDF</a>
    </div>
    <p data-pdf-loading role="status" class="p-3 text-sm">Loading PDF…</p>
    <p data-pdf-error role="alert" hidden class="p-3 text-sm text-red-700 dark:text-red-300"></p>
    <div data-pdf-scroll tabindex="0" aria-label="PDF pages" style="flex:1;min-height:0;overflow:auto;background:#52525b;overscroll-behavior:contain;">
        <div data-pdf-pages style="position:relative;min-width:100%;padding:1px 0;"></div>
    </div>
</div>
