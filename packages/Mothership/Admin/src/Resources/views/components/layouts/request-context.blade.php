@props(['signedIn' => true])

{{-- Wording and endpoints for failed requests and the session notice (assets/js/plugins/request-errors.js).
     Every layout that loads the admin bundle includes this before it. Signed-out pages get no probe endpoint,
     so they can tell they are the sign-in page but never raise the "sign in again" notice. --}}
<script>
    window.mothershipRequestText = @json(trans('admin::app.requests'));
    window.mothershipSession = @json(array_filter(['login' => route('admin.session.create'), 'token' => $signedIn ? route('admin.session.token') : null]));
</script>
