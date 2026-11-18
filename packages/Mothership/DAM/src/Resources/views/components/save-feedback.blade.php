<p
    v-if="feedback.{{ $action }}.message"
    :role="feedback.{{ $action }}.state === 'error' ? 'alert' : 'status'"
    aria-atomic="true"
    class="mt-2 flex max-w-lg items-start gap-2 text-sm"
    :class="feedback.{{ $action }}.state === 'error' ? 'text-red-600 dark:text-red-400' : feedback.{{ $action }}.state === 'saved' ? 'text-green-700 dark:text-green-400' : 'text-gray-500'"
>
    <span v-if="feedback.{{ $action }}.state === 'saved'" aria-hidden="true">✓</span>
    <span v-if="feedback.{{ $action }}.state === 'error'" aria-hidden="true">!</span>
    <span v-text="feedback.{{ $action }}.message"></span>
</p>
