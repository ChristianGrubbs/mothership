{{--
    A long file name keeps its ending visible. The files of one stack share most of their name
    (DJI_20261007140405_0157_D.DNG next to DJI_20261007140405_0157_D.JPG), so an ellipsis at the
    end hides the only part that tells them apart. Render `head` in a truncating box and `tail`
    beside it in a box that never shrinks; a short name is all head.
--}}
@pushOnce('scripts')
    <script>
        window.MothershipNameTail = (name) => {
            const text = String(name ?? '');
            // Up to 24 characters fit as they are.
            if (text.length <= 24) return { head: text, tail: '' };

            // Start the tail at the first separator (space, _, - or .) inside the final 16 characters, so a word is
            // not split ("Season-TV ad.png" rather than "n-TV ad.png") and the extension stays. A camera counter and
            // extension ("_0157_D.DNG") fit, so DNG/JPG twins keep different tails.
            const last = text.slice(-16);
            const cut = last.search(/[ _.\-]/);
            // No separator in that window: fall back to eleven characters ("0157_D.JPG").
            const start = cut >= 0 ? text.length - 16 + cut : text.length - 11;

            return { head: text.slice(0, start), tail: text.slice(start) };
        };
    </script>
@endPushOnce
