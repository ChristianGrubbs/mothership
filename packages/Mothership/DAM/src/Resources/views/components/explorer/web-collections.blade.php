<v-dam-web-collections></v-dam-web-collections>

@once('v-dam-web-collections')
@push('scripts')
<script type="text/x-template" id="v-dam-web-collections-template">
    <div class="flex flex-col gap-1 max-h-56 overflow-y-auto pr-1" data-web-collections-scroll>
        <a
            :href="pagesUrl"
            class="flex items-center gap-2 px-2 py-1.5 rounded-md transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800 text-zinc-700 dark:text-white"
            data-web-collection-root
        >
            <i class="icon-dam-link text-lg text-primary-400 shrink-0"></i>
            <span class="text-sm flex-1 truncate">@lang('dam::app.admin.explorer.web-collections.all')</span>
        </a>

        <a
            v-for="node in flat"
            :key="node.id"
            :href="pagesUrl + '?collection=' + node.id"
            :style="{ paddingLeft: (8 + node.depth * 14) + 'px' }"
            class="flex items-center gap-2 pr-2 py-1.5 rounded-md transition-colors hover:bg-gray-100 dark:hover:bg-cherry-800 text-zinc-700 dark:text-white"
            :data-web-collection-id="node.id"
            :title="node.name"
        >
            <i class="icon-dam-folder text-lg text-primary-400 shrink-0"></i>
            <span class="text-sm flex-1 truncate">@{{ node.name }}</span>
            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">(@{{ node.total_page_count }})</span>
        </a>

        <p v-if="loaded && ! flat.length" class="text-xs text-gray-400 dark:text-gray-500 text-center px-2 py-3">
            @lang('dam::app.admin.explorer.web-collections.empty')
        </p>
    </div>
</script>

<script type="module">
app.component('v-dam-web-collections', {
    template: '#v-dam-web-collections-template',

    data() {
        return {
            pagesUrl: "{{ route('admin.dam.pages.index') }}",
            flat: [],
            loaded: false,
        };
    },

    mounted() {
        this.load();
    },

    methods: {
        // Web page collections live in their own tree (ADR 0008), not under the Library root, so
        // the Library sidebar lists them here as jump links into the Web Pages browser.
        load() {
            this.$axios.get("{{ route('admin.dam.pages.listing') }}", { params: { tree: 1 } })
                .then(({ data }) => { this.flat = this.flatten(data.children ?? []); })
                .catch(() => { this.flat = []; })
                .finally(() => { this.loaded = true; });
        },

        flatten(nodes, depth = 0, out = []) {
            for (const node of nodes) {
                out.push({ id: node.id, name: node.name, total_page_count: node.total_page_count ?? node.page_count ?? 0, depth });
                if (node.children?.length) this.flatten(node.children, depth + 1, out);
            }
            return out;
        },
    },
});
</script>
@endpush
@endonce
