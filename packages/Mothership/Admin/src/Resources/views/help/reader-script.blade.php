<style data-documentation-reader-style>
[data-documentation-article] {line-height:1.7; overflow-wrap:anywhere}
[data-documentation-article] h1 {font-size:1.8rem; font-weight:600}
[data-documentation-article] h2 {font-size:1.3rem; font-weight:600; margin-top:1.5rem}
[data-documentation-article] p,[data-documentation-article] ul,[data-documentation-article] ol {margin-block:1rem}
[data-documentation-article] ul {list-style:disc; padding-left:1.5rem}
[data-documentation-article] ol {list-style:decimal; padding-left:1.5rem}
[data-documentation-article] pre {overflow:auto; padding:1rem; border:1px solid #64748b; border-radius:.5rem}
[data-documentation-article] table {display:block; overflow:auto; border-collapse:collapse}
[data-documentation-article] td,[data-documentation-article] th {border:1px solid #64748b; padding:.5rem}
</style>
<script>
(() => {
    const readerStyle = document.querySelector('#app style[data-documentation-reader-style]');

    if (readerStyle) {
        document.head.querySelectorAll('style[data-documentation-reader-style]').forEach(style => style.remove());
        document.head.appendChild(readerStyle);
    }

    const mount = () => document.querySelectorAll('[data-documentation-article] pre').forEach(pre => {
        if (pre.dataset.copyReady) return;
        pre.dataset.copyReady = '1';
        const button = document.createElement('button');
        button.type = 'button'; button.textContent = 'Copy code'; button.className = 'secondary-button';
        button.dataset.documentationCopy = '';
        pre.before(button);
    });
    if (!window.__documentationCopyListener) {
        document.addEventListener('mothership:navigate:success', mount);
        document.addEventListener('click', async event => {
            const button = event.target.closest?.('[data-documentation-copy]');

            if (!button) return;

            const pre = button.nextElementSibling;

            try { await navigator.clipboard.writeText(pre.querySelector('code')?.textContent || pre.textContent); button.textContent = 'Copied'; }
            catch { button.textContent = 'Select and copy the code below'; }
        });
        window.__documentationCopyListener = true;
    }
    mount();
})();
</script>
