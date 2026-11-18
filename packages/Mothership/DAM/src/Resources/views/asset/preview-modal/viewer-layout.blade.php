{{--
    The viewer's layout on a phone, on both iPhone Duo displays and on any short screen; desktop keeps viewer-modal's own
    classes. Shared by both viewers (the Library lightbox and the details page), through viewer-modal; pushed to the
    layout's styles, since a <style> inside the viewer's x-template would not reach the page.

    Compact, (max-width: 1023px) or (max-height: 600px): the dialog becomes a grid. The header and the navigation give
    their children to it (display: contents), so one bar holds back, type, title and close; the actions sit in their own
    bar under it beside the stack's ‹ File n of N ›; the file strip follows; the picture takes the rest. Previous and
    next Library result float on the picture's edges, so they cost no height.

    Short, (max-height: 500px) (a phone on its side; the Duo's outer display in landscape is 368px tall): the actions
    stand in a column on the right of the picture, as the Duo's own bars turn vertical in landscape, and the strip
    drops its names to one line beside its count.

    Laptop pose (html[data-fold="rows"], two stacked viewport segments, public/pwa/device.js): the picture fills the upper
    segment and every bar sits in the lower one, so no control is on the fold. Book pose is device.css's: the dialog docks
    in the trailing segment and takes this compact layout there. device.css still sets the dialog's size and grid rows
    for the fold; the area names below line up with its row numbers (the strip is row 5).
--}}
@pushOnce('styles')
<style>
    [data-dam-preview-tools] { display: contents; }
    [data-dam-nav-glyph], [data-dam-compact-icon] { display: none; }

    @media (max-width: 1023px), (max-height: 600px) {
        section[data-dam-preview-dialog] {
            display: grid;
            grid-template-columns: auto auto minmax(0, 1fr) auto auto;
            grid-template-rows: auto auto minmax(0, auto) auto minmax(0, 1fr);
            grid-template-areas:
                "back  ext   title title close"
                "tools tools tools tools tools"
                "lib   lib   lib   mnav  mnav"
                "strip strip strip strip strip"
                "stage stage stage stage stage";
            column-gap: .5rem;
            align-items: center;
        }
        section[data-dam-preview-dialog] > [data-dam-preview-header],
        section[data-dam-preview-dialog] > [data-dam-preview-nav] { display: contents; }

        /* Title bar */
        [data-dam-preview-back] { grid-area: back; justify-content: center; width: 2.5rem; height: 2.5rem; padding: 0; margin: .375rem 0 .375rem .5rem; }
        [data-dam-preview-back] [data-dam-preview-label] { display: none; }
        [data-dam-preview-ext] { grid-area: ext; }
        [data-dam-preview-variant], [data-dam-preview-title] { grid-area: title; min-width: 0; }
        [data-dam-preview-variant] p, [data-dam-preview-title] { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        [data-dam-preview-variant] p + p { margin-top: 0; }
        section[data-dam-preview-dialog] [data-dam-preview-close] { grid-area: close; justify-self: end; position: static; margin: .375rem .5rem .375rem 0; box-shadow: none; }

        /* Actions bar */
        [data-dam-preview-tools] { grid-area: tools; display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; min-width: 0; margin: 0 0 .5rem .5rem; }
        [data-dam-preview-tools] [data-dam-compact-icon] { display: block; flex: none; }
        [data-dam-preview-files-link] {
            display: inline-flex; align-items: center; justify-content: center; min-width: 2.5rem; min-height: 2.5rem;
            border: 1px solid rgb(229 231 235); border-radius: .5rem; background: #fff;
        }
        .dark [data-dam-preview-files-link] { border-color: rgb(55 65 81); background: rgb(31 41 55); }
        [data-dam-preview-files-link] [data-dam-preview-label] { display: none; }
        [data-dam-preview-download-as] > button { height: 2.5rem !important; min-width: 2.5rem; justify-content: center; padding: 0 .5rem !important; }
        [data-dam-open-details] { gap: .375rem; }
        [data-dam-open-details] [data-dam-compact-icon] { display: none; }

        /* The stack's own ‹ File n of N › beside the actions; Back to Library on its own line when it is offered. */
        [data-dam-member-navigation] { grid-area: mnav; justify-self: end; gap: .25rem; margin: 0 .5rem .5rem 0; font-size: .75rem; white-space: nowrap; }
        [data-dam-preview-nav] button[data-dam-member-prev], [data-dam-preview-nav] button[data-dam-member-next] { display: flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; padding: 0; border-radius: .5rem; }
        [data-dam-preview-nav] [data-dam-nav-text] { display: none; }
        [data-dam-preview-nav] [data-dam-nav-glyph] { display: block; width: 1.25rem; height: 1.25rem; }
        [data-dam-preview-back-library] { grid-area: lib; justify-self: start; margin: 0 .5rem .5rem; }

        /* Previous and next Library result ride on the picture's edges. */
        [data-dam-result-navigation] {
            grid-area: stage; align-self: center; justify-self: stretch; z-index: 5;
            display: flex; justify-content: space-between; padding: 0 .5rem; pointer-events: none;
        }
        [data-dam-result-navigation] button {
            display: flex; align-items: center; justify-content: center; width: 2.75rem; height: 2.75rem; padding: 0;
            border: 0; border-radius: 9999px; background: rgb(17 24 39 / .6); color: #fff; pointer-events: auto;
            box-shadow: 0 0 0 1px rgb(255 255 255 / .55), 0 1px 4px rgb(0 0 0 / .35);
        }
        [data-dam-result-navigation] button:disabled { opacity: 0; pointer-events: none; }

        section[data-dam-preview-dialog] > [data-dam-attachment-strip],
        section[data-dam-preview-dialog] > [data-dam-strip-no-access] { grid-area: strip; align-self: stretch; }
        section[data-dam-preview-dialog] > [data-dam-preview-stage] { grid-area: stage; align-self: stretch; min-height: 0; border-top: 1px solid rgb(229 231 235); }
        .dark section[data-dam-preview-dialog] > [data-dam-preview-stage] { border-top-color: rgb(55 65 81); }

        /* The PDF controls, tighter; upright they wrap to a second row, so page turning stays in view. */
        [data-dam-preview-dialog] [data-pdf-toolbar] { justify-content: center; gap: .375rem; padding: .375rem .5rem; }
        [data-dam-preview-dialog] [data-pdf-toolbar] .secondary-button { min-width: 2.5rem; min-height: 2.5rem; justify-content: center; padding-left: .625rem; padding-right: .625rem; }
        [data-dam-preview-dialog] [data-pdf-toolbar] [data-pdf-page-number] { width: 3.25rem; }
        [data-dam-preview-dialog] [data-pdf-toolbar] [data-pdf-original] { padding-right: 1.5rem; }

        /* Narrow: Open details goes icon-only like the rest, so the actions keep one row; a stack's ‹ File n of N ›
           takes the line under them. From 760px wide it fits beside them (the Duo's inner display upright is 669px). */
        @media (max-width: 639px) {
            [data-dam-open-details] { width: 2.5rem; height: 2.5rem; padding: 0 !important; justify-content: center; }
            [data-dam-open-details] [data-dam-compact-icon] { display: block; }
            [data-dam-open-details] [data-dam-preview-label] { display: none; }
        }
        @media (min-width: 760px) {
            section[data-dam-preview-dialog] {
                grid-template-areas:
                    "back  ext   title title close"
                    "tools tools tools mnav  mnav"
                    "lib   lib   lib   lib   lib"
                    "strip strip strip strip strip"
                    "stage stage stage stage stage";
            }
        }

        /* On a fold the dialog is one segment wide (476px in book pose), whatever the viewport's width: Open details goes
           icon-only, and in book pose the size and result count leave the bar (as in the short layout, below) and
           ‹ File n of N › takes its own line, as on a phone. */
        html[data-fold] [data-dam-open-details] { width: 2.5rem; height: 2.5rem; padding: 0 !important; justify-content: center; }
        html[data-fold] [data-dam-open-details] [data-dam-compact-icon] { display: block; }
        html[data-fold] [data-dam-open-details] [data-dam-preview-label] { display: none; }
        html[data-fold="columns"] [data-dam-preview-tools] > [data-dam-preview-size],
        html[data-fold="columns"] [data-dam-preview-tools] > [data-dam-preview-position] { display: none; }
        html[data-fold="columns"] section[data-dam-preview-dialog] {
            grid-template-areas:
                "back  ext   title title close"
                "tools tools tools tools tools"
                "lib   lib   lib   mnav  mnav"
                "strip strip strip strip strip"
                "stage stage stage stage stage";
        }

        /* Laptop pose: the picture in the upper segment, every bar in the lower one. */
        html[data-fold="rows"] section[data-dam-preview-dialog] {
            grid-template-rows: var(--seg-a-height) var(--fold-gap, 0px) auto auto auto auto minmax(0, 1fr);
            grid-template-areas:
                "stage stage stage stage stage"
                ".     .     .     .     ."
                "back  ext   title title close"
                "tools tools tools mnav  mnav"
                "strip strip strip strip strip"
                "lib   lib   lib   lib   lib"
                ".     .     .     .     .";
        }
        html[data-fold="rows"] section[data-dam-preview-dialog] > [data-dam-preview-stage] { border-top: 0; }
    }

    @media (max-height: 500px) {
        section[data-dam-preview-dialog] {
            grid-template-rows: auto minmax(0, auto) minmax(0, 1fr) auto;
            grid-template-areas:
                "back  ext   title mnav  close"
                "lib   lib   lib   lib   tools"
                "stage stage stage stage tools"
                "strip strip strip strip tools";
        }
        [data-dam-member-navigation] { margin: .375rem 0; }
        /* The column holds controls only. The size is also in the Info pane; the result count is left out, and the arrows on
           the picture still show whether a previous or next result exists. */
        [data-dam-preview-tools] > [data-dam-preview-size], [data-dam-preview-tools] > [data-dam-preview-position] { display: none; }
        [data-dam-preview-tools] {
            display: grid; grid-auto-flow: column; grid-template-rows: repeat(auto-fill, 2.5rem); gap: .25rem;
            align-self: stretch; align-content: start; justify-items: center;
            max-height: calc(100vh - 3.5rem); max-height: calc(100dvh - 3.5rem);
            margin: 0; padding: .25rem .5rem .25rem .375rem; border-left: 1px solid rgb(229 231 235);
        }
        .dark [data-dam-preview-tools] { border-left-color: rgb(55 65 81); }
        [data-dam-open-details] { width: 2.5rem; height: 2.5rem; padding: 0 !important; justify-content: center; }
        [data-dam-open-details] [data-dam-compact-icon] { display: block; }
        [data-dam-open-details] [data-dam-preview-label] { display: none; }
        /* No height for a second row: the PDF controls keep one row and scroll sideways, the fade showing there is more. */
        [data-dam-preview-dialog] [data-pdf-toolbar] {
            flex-wrap: nowrap; justify-content: flex-start; overflow-x: auto; white-space: nowrap;
            -webkit-mask-image: linear-gradient(to right, #000 calc(100% - 1.5rem), transparent);
            mask-image: linear-gradient(to right, #000 calc(100% - 1.5rem), transparent);
        }
        [data-dam-preview-dialog] [data-pdf-toolbar] > * { flex: none; }
        [data-dam-preview-tools] { position: relative; }
    }

    /* The video bar's skip, speed, quality, loop and volume controls (.dam-ctrl-desktop) show from a 525px viewport, but
       the player can be narrower than its viewport (beside the action column on a phone on its side, 505px): there they
       pushed fullscreen and the ⋮ menu out of reach. The same 525px now reads the player's own width, so a player as
       wide as its viewport keeps them exactly as before. */
    [data-dam-flat-video-player] { container-type: inline-size; }
    @container (max-width: 524px) {
        [data-dam-flat-video-player] .dam-ctrl-desktop { display: none; }
    }

    /* The image zoom controls were 28px squares; a finger needs more. */
    @media (pointer: coarse) {
        [data-dam-image-toolbar] button { width: 2.25rem; height: 2.25rem; }
    }
</style>
@endPushOnce
