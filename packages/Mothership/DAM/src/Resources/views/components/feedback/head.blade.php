{{--
    Feedback & bug reports: the console buffer and redaction helper (assets/js/feedback/feedback.js), inlined early in
    <head> so the report dialog can offer the page's recent errors (redacted, last 20). Rendered only while
    dam.feedback.enabled is on, for a signed-in non-API person. Every admin layout that renders the header includes it.
--}}
@if ((bool) config('dam.feedback.enabled') && app(\Mothership\DAM\Services\Feedback\FeedbackAccess::class)->canSubmit(auth()->guard('admin')->user()))
    <script>{!! file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/feedback/feedback.js')) !!}</script>
@endif
