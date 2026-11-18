@php($items = [1, 2])
@pushOnce('scripts')
<script type="module">
    const config = @json(['a' => 1, 'b' => "it's"]);
    const url = "{{ route('admin.dam.index', ['x' => 1]) }}";
    @if ($flag)
        const mode = 'a';
    @endif
    const label = "@lang('dam::app.title')";
    const total = await Promise.resolve({!! $count !!});
    app.component('v-demo', { template: '#v-demo-template', data() { return { config, url, label, total }; } });
</script>
<script type="text/x-template" id="v-demo-template">
    <button :class="{ 'is-on': on }" @click="on = ! on; $emit('toggle', on)">@{{ label }}</button>
</script>
@endPushOnce
