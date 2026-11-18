{{--
    The sidebar draws its icons from two icon fonts (mothership-admin and the DAM's Mothership-DAM). Left
    to first layout they queue behind the theme's text faces and land at different moments, so
    the rail showed two of its five icons before the rest popped in (UI QA F1). Request both now,
    and keep the rail's glyphs hidden until both are ready (at most 3 s) so they appear together.
--}}
<style>
    .icon-fonts-pending #mothership-sidebar [class*="icon-"]::before { visibility: hidden; }
    /* The avatar keeps one colour on every page; keyboard focus shows as a ring, not a new fill. */
    [data-account-avatar]:focus-visible { outline: 2px solid #2D5BE3; outline-offset: 2px; }
</style>
<script>
    (() => {
        if (! document.fonts?.load) {
            return;
        }

        const root = document.documentElement;
        const reveal = () => root.classList.remove('icon-fonts-pending');

        root.classList.add('icon-fonts-pending');

        Promise.all(['mothership-admin', 'Mothership-DAM'].map((family) => document.fonts.load(`24px "${family}"`))).then(reveal, reveal);

        setTimeout(reveal, 3000);
    })();
</script>
