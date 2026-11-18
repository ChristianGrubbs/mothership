{{-- Every admin page's collector (P1b), added through the layout's body.after render event. --}}
@php($collector = app(\Mothership\DAM\Analytics\CollectorConfig::class)->forAdmin(admin: auth('admin')->user(), request: request()))

@include('dam::analytics.collector', ['collector' => $collector])

@if ($collector !== null)
    {{-- The upload file picker, wherever a page puts one: opened, or closed with nothing chosen (the input's cancel
         event). Choosing files is not tracked here: the server records each upload's attempt and outcome. --}}
    <script type="module">
        const picker = (event) => event.target.matches?.('input[type=file][name="files[]"], input[type=file][name="folder_files[]"]');

        document.addEventListener('click', (event) => picker(event) && window.damTrack?.('upload.dialog.opened', 0, {}, 'upload'), true);
        document.addEventListener('cancel', (event) => picker(event) && window.damTrack?.('upload.dialog.abandoned', 0, { files: 0 }, 'upload'), true);
    </script>
@endif
