{{-- The Adobe application marks, once per page: payloads carry `adobe.key`, never a picture (Support\AdobeFormats). --}}
@pushOnce('scripts')
    <script>
        window.MothershipAdobe = {
            icons: @js(\Mothership\DAM\Support\AdobeFormats::icons()),
            tiles: @js(\Mothership\DAM\Support\AdobeFormats::tiles()),
            /** The mark (data URI) for an `adobe` payload, or '' when the file is not an Adobe format. */
            icon(adobe) {
                return (adobe && this.icons[adobe.key]) || '';
            },
            /** The application's mark on the viewer's placeholder canvas, for a file with no picture of its own. */
            tile(adobe) {
                return (adobe && this.tiles[adobe.key]) || '';
            },
        };
    </script>
@endPushOnce
