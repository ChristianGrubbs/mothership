# Adobe application marks

Provenance for the SVGs in `packages/Mothership/DAM/src/Resources/assets/images/adobe/`. It lives here, not beside them, because
`assets/js/app.js` imports everything under `assets/images/**` through `import.meta.glob`, and Vite would parse a Markdown file there as JavaScript.

Product marks for Adobe Illustrator, Photoshop, InDesign, After Effects, Premiere Pro, XD and Audition, shown beside
files made by those applications (the customer asked for the software logos). One SVG per mark, no scripts, no
external references, no raster images; they are read from the package at request time (`Support/AdobeFormats`), never
fetched from a CDN.

| File | Source (Wikimedia Commons file page) | Commons license field | Retrieved |
|---|---|---|---|
| illustrator.svg | https://commons.wikimedia.org/wiki/File:Adobe_Illustrator_CC_icon.svg | Public domain | 2026-10-15 |
| photoshop.svg | https://commons.wikimedia.org/wiki/File:Adobe_Photoshop_CC_icon.svg | Public domain | 2026-10-15 |
| indesign.svg | https://commons.wikimedia.org/wiki/File:Adobe_InDesign_CC_icon.svg | Public domain | 2026-10-15 |
| after-effects.svg | https://commons.wikimedia.org/wiki/File:Adobe_After_Effects_CC_icon.svg | Public domain | 2026-10-15 |
| premiere-pro.svg | https://commons.wikimedia.org/wiki/File:Adobe_Premiere_Pro_CC_icon.svg | Public domain | 2026-10-15 |
| xd.svg | https://commons.wikimedia.org/wiki/File:Adobe_XD_CC_icon.svg | Public domain | 2026-10-15 |
| audition.svg | https://commons.wikimedia.org/wiki/File:Adobe_Audition_CC_icon.svg | Public domain | 2026-10-15 |

Original file URLs were taken from the Commons API (`action=query&prop=imageinfo`), not guessed:
`https://upload.wikimedia.org/wikipedia/commons/<hash>/Adobe_<Name>_CC_icon.svg`.

Sanitising applied to each download: XML comments (the Adobe export generator note), the XML prolog, the unused
`xmlns:xlink` declaration, `x="0px"`/`y="0px"`, `enable-background` and `xml:space` attributes, and whitespace were removed.
Paths, colours and `viewBox` are untouched. `tests/Feature/AdobeFormatsTest.php` fails if any file gains a script,
link, `<use>`, `<image>`, `<foreignObject>`, event handler or `url()` reference.

"Adobe" and the application names are trademarks of Adobe Inc.; the marks identify files made with that software.
