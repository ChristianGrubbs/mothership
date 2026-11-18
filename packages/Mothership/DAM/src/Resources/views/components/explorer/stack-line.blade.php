{{-- One line for a card or list row: a stack's basis and key, then how many files the entry holds (D5). --}}
@pushOnce('scripts')
    <script>
        window.MothershipStackLine = (asset) => {
            const bases = @js(trans('dam::app.admin.dam.asset.stack.bases'));
            const count = Number(asset?.attachment_count || 0);
            const files = count > 1 ? @js(trans('dam::app.admin.dam.asset.stack.files-count')).replace(':count', () => count) : '';
            const stack = asset?.stack_basis ? `${bases[asset.stack_basis] ?? asset.stack_basis} · ${asset.stack_key ?? ''}` : '';

            return [stack, files].filter(Boolean).join(' · ');
        };
        // G1-11: deleting a stack says how many files go to Trash with it; any other entry keeps the generic question.
        window.MothershipStackDeleteMessage = (asset) => {
            const count = Number(asset?.attachment_count || 0);

            return count > 1 ? @js(trans('dam::app.admin.dam.asset.stack.delete-confirm')).replace(':count', () => count) : undefined;
        };
    </script>
@endPushOnce
