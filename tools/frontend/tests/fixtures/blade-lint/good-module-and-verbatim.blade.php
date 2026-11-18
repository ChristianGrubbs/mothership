@verbatim
    <img @error="broken = true">
@endverbatim
<script type="module">
    import { ref } from 'vue';
    export const state = ref(0);
    export default { state };
</script>
