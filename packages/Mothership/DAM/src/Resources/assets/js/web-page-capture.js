// Runs inside the page sandbox (opaque origin). It cannot read the parent; it can only post to it.
(() => {
  if (window.top === window || window.__mothershipCaptured) return;
  window.__mothershipCaptured = true;

  const isSameOrigin = (url) => {
    try { return new URL(url, document.baseURI).origin === window.location.origin; } catch (error) { return false; }
  };

  const fetchAsText = async (url) => {
    const response = await fetch(url);
    if (! response.ok) throw new Error('fetch failed: ' + response.status);
    return response.text();
  };

  const fetchAsDataUrl = async (url) => {
    const response = await fetch(url);
    if (! response.ok) throw new Error('fetch failed: ' + response.status);
    const blob = await response.blob();

    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = reject;
      reader.readAsDataURL(blob);
    });
  };

  /** Replaces every regex match in `text` with the (possibly async) result of `replacer(match)`,
   * or leaves it as-is when the replacer returns null/undefined. `regex` must be global. */
  const replaceAllAsync = async (text, regex, replacer) => {
    const matches = [...text.matchAll(regex)];
    const replacements = await Promise.all(matches.map((match) => replacer(match)));
    let result = '';
    let lastIndex = 0;
    matches.forEach((match, index) => {
      result += text.slice(lastIndex, match.index);
      result += replacements[index] ?? match[0];
      lastIndex = match.index + match[0].length;
    });

    return result + text.slice(lastIndex);
  };

  /**
   * Resolves one level of @import (same-origin only, spliced in verbatim -- an @import inside
   * that imported text is left as-is rather than recursing) and rewrites same-origin url(...)
   * references (background-image, @font-face src, etc.) to data URLs. `data:`, `blob:` and
   * fragment (`#...`) references are left untouched; anything that fails to resolve or fetch
   * marks the capture partial but never throws.
   */
  const inlineStylesheetText = async (css, href) => {
    let partial = false;

    css = await replaceAllAsync(css, /@import\s+(?:url\(\s*(['"]?)([^'")]+)\1\s*\)|(['"])([^'"]+)\3)[^;]*;/gi, async (match) => {
      const ref = match[2] || match[4];
      if (! ref) { partial = true; return null; }
      let absolute;
      try { absolute = new URL(ref, href).href; } catch (error) { partial = true; return null; }
      if (! isSameOrigin(absolute)) { partial = true; return null; }
      try { return await fetchAsText(absolute); } catch (error) { partial = true; return null; }
    });

    css = await replaceAllAsync(css, /url\(\s*(['"]?)([^'")]+)\1\s*\)/gi, async (match) => {
      const ref = match[2];
      if (! ref || ref.startsWith('data:') || ref.startsWith('blob:') || ref.startsWith('#')) return null;
      let absolute;
      try { absolute = new URL(ref, href).href; } catch (error) { partial = true; return null; }
      if (! isSameOrigin(absolute)) { partial = true; return null; }
      try { return `url("${await fetchAsDataUrl(absolute)}")`; } catch (error) { partial = true; return null; }
    });

    return { css, partial };
  };

  /**
   * Builds a self-contained clone of the document so the snapshot doesn't rely on the sandboxed
   * iframe's opaque origin being able to load its own siblings a second time inside the SVG
   * foreignObject (it can't: same-origin fetches are fine, but the foreignObject's own resource
   * loads are subject to the sandbox all over again and silently fail for many browsers). Inlines
   * stylesheets as <style> (one level of @import, same-origin url() as data URLs), same-origin
   * images (including responsive img/picture, resolved to the browser's own currentSrc choice)
   * as data URLs, and already-rendered <canvas> content as <img>. Fonts declared via @font-face
   * are inlined by the url() rewrite above when same-origin; a cross-origin font CDN is NOT
   * fetched (opaque-origin CORS) and is left as a dead reference -- deferred, the capture is a
   * thumbnail, not a font-exact snapshot.
   */
  const buildSelfContainedClone = async () => {
    const clone = document.documentElement.cloneNode(true);
    let partial = false;

    const links = Array.from(clone.querySelectorAll('link[rel~="stylesheet"]'));
    await Promise.all(links.map(async (link) => {
      const href = link.getAttribute('href');
      if (! href || ! isSameOrigin(href)) { partial = true; return; }
      const absoluteHref = new URL(href, document.baseURI).href;
      try {
        const css = await fetchAsText(absoluteHref);
        const { css: inlined, partial: stylesheetPartial } = await inlineStylesheetText(css, absoluteHref);
        if (stylesheetPartial) partial = true;
        const style = document.createElement('style');
        style.textContent = inlined;
        link.replaceWith(style);
      } catch (error) { partial = true; }
    }));

    // A source's srcset/media the parent never resolved is not inlineable, so <picture> is
    // unwrapped to just its fallback <img> -- which is then resolved via currentSrc below, the
    // same element the browser actually decided to render.
    Array.from(clone.querySelectorAll('picture')).forEach((picture) => {
      Array.from(picture.querySelectorAll('source')).forEach((source) => source.remove());
    });

    const originalImages = Array.from(document.querySelectorAll('img'));
    const clonedImages = Array.from(clone.querySelectorAll('img'));
    await Promise.all(clonedImages.map(async (img, index) => {
      const original = originalImages[index];
      const resolvedSrc = (original && (original.currentSrc || original.src)) || img.getAttribute('src');
      img.removeAttribute('srcset');
      img.removeAttribute('sizes');
      if (! resolvedSrc || ! isSameOrigin(resolvedSrc)) { partial = true; return; }
      try {
        img.setAttribute('src', await fetchAsDataUrl(resolvedSrc));
      } catch (error) { partial = true; }
    }));

    const originalCanvases = Array.from(document.querySelectorAll('canvas'));
    Array.from(clone.querySelectorAll('canvas')).forEach((clonedCanvas, index) => {
      try {
        const dataUrl = originalCanvases[index].toDataURL();
        const img = document.createElement('img');
        img.setAttribute('src', dataUrl);
        img.setAttribute('width', clonedCanvas.getAttribute('width') || clonedCanvas.width || '');
        img.setAttribute('height', clonedCanvas.getAttribute('height') || clonedCanvas.height || '');
        clonedCanvas.replaceWith(img);
      } catch (error) { partial = true; } // tainted canvas: left as an empty <canvas> in the clone
    });

    return { clone, partial };
  };

  const capture = async () => {
    let partial = false;

    try {
      const { clone, partial: inliningPartial } = await buildSelfContainedClone();
      partial = inliningPartial;

      const pageWidth = Math.min(document.documentElement.scrollWidth || 1024, 1600);
      const pageHeight = Math.round(pageWidth * 9 / 16);
      const xml = new XMLSerializer().serializeToString(clone);
      const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${pageWidth}" height="${pageHeight}">` +
        `<foreignObject width="100%" height="100%">${xml}</foreignObject></svg>`;
      const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
      const image = new Image();
      await new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; image.src = url; });
      const canvas = document.createElement('canvas');
      canvas.width = pageWidth; canvas.height = pageHeight;
      const context = canvas.getContext('2d');
      context.fillStyle = '#ffffff'; context.fillRect(0, 0, pageWidth, pageHeight);
      context.drawImage(image, 0, 0, pageWidth, pageHeight);
      const jpeg = canvas.toDataURL('image/jpeg', 0.85); // throws on a tainted canvas
      const message = { type: 'mothership:web-page-thumbnail', jpeg };
      if (partial) message.partial = true; // best effort: a partial capture still beats none
      window.parent.postMessage(message, '*');
    } catch (error) {
      window.parent.postMessage({ type: 'mothership:web-page-thumbnail', error: String(error && error.message || error) }, '*');
    }
  };

  const start = () => setTimeout(capture, 800);
  document.readyState === 'complete' ? start() : window.addEventListener('load', start, { once: true });
})();
