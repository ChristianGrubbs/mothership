{{--
    The details page's one record of "a stacked file other than the cover is on show on the Preview tab". The preview
    component says so (`dam:stack-file-shown`, with the file, or null for the cover); every component that spreads this
    mixin then has `shownFile` and `stackFileShown`. While another file is on show the header offers only the note,
    "Back to cover" and that file's own Open with (everything else sits inside <v-dam-cover-only>, hidden but kept alive), and every entry point that
    changes the cover or the stack asks `assertCoverShown()` first and does nothing if it says no. The Details card
    follows `shownFile`. A new entry (the ‹ › chevrons) puts the cover back.
--}}
<script>
    window._damShownStackFile = {
        data() {
            return { shownFile: null };
        },
        computed: {
            stackFileShown() {
                return !! this.shownFile;
            },
        },
        methods: {
            /** True when the cover is on show and a change to it or to the stack may go ahead; false means do nothing. */
            assertCoverShown() {
                return ! this.stackFileShown;
            },
        },
        mounted() {
            this._onStackFileShown = ({ file } = {}) => { this.shownFile = file ?? null; };
            this._onStackEntryChanged = (data) => { if (data?.asset?.id) this.shownFile = null; };
            this.$emitter.on('dam:stack-file-shown', this._onStackFileShown);
            this.$emitter.on('dam-asset-changed', this._onStackEntryChanged);
        },
        beforeUnmount() {
            this.$emitter.off('dam:stack-file-shown', this._onStackFileShown);
            this.$emitter.off('dam-asset-changed', this._onStackEntryChanged);
        },
    };
</script>
