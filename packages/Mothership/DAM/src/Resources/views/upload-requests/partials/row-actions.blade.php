{{--
    P5a (D1): one request's actions, on the list and on the request page: Copy link (only when the server shows the
    URL, per #126), Edit, Close and Reissue (creator and full admins). Emits `changed` after Close or Reissue.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-dam-upload-request-actions-template">
        <span class="flex flex-wrap gap-2 xl:flex-nowrap" data-dam-upload-request-actions>
            <button v-if="request.url && request.status === 'open'" type="button" class="secondary-button whitespace-nowrap" data-dam-upload-request-copy-link @click="copy(request.url)">
                @lang('dam::app.admin.upload-requests.copy-link')
            </button>
            <button v-if="request.can_manage" type="button" class="secondary-button whitespace-nowrap" data-dam-upload-request-edit @click="edit">
                @lang('dam::app.admin.upload-requests.edit')
            </button>
            <button v-if="request.can_manage && request.status === 'open'" type="button" class="secondary-button whitespace-nowrap" :disabled="busy" data-dam-upload-request-reissue @click="confirmReissue">
                @lang('dam::app.admin.upload-requests.reissue')
            </button>
            <button v-if="request.can_manage && request.status === 'open'" type="button" class="danger-button whitespace-nowrap" :disabled="busy" data-dam-upload-request-close @click="confirmClose">
                @lang('dam::app.admin.upload-requests.close')
            </button>
        </span>
    </script>

    <script type="module">
        app.component('v-dam-upload-request-actions', {
            template: '#v-dam-upload-request-actions-template',

            props: { request: { type: Object, required: true } },

            emits: ['changed'],

            data() {
                return { busy: false };
            },

            methods: {
                edit() {
                    this.$emitter.emit('open-upload-request-modal', { request: this.request });
                },

                confirmClose() {
                    this.$emitter.emit('open-confirm-modal', {
                        title: @js(trans('dam::app.admin.upload-requests.close-confirm-title')),
                        message: @js(trans('dam::app.admin.upload-requests.close-confirm-message')),
                        options: {
                            btnDisagree: @js(trans('dam::app.admin.upload-requests.modal.cancel')),
                            btnAgree: @js(trans('dam::app.admin.upload-requests.close')),
                            btnAgreeClass: 'danger-button',
                            btnDisagreeClass: 'transparent-button',
                        },
                        agree: () => this.act(() => this.$axios.patch(@js(route('admin.dam.upload-requests.close', ':id')).replace(':id', this.request.id))),
                        disagree: () => {},
                    });
                },

                confirmReissue() {
                    this.$emitter.emit('open-confirm-modal', {
                        title: @js(trans('dam::app.admin.upload-requests.reissue-confirm-title')),
                        message: @js(trans('dam::app.admin.upload-requests.reissue-confirm-message')),
                        options: {
                            btnDisagree: @js(trans('dam::app.admin.upload-requests.modal.cancel')),
                            btnAgree: @js(trans('dam::app.admin.upload-requests.reissue')),
                            btnAgreeClass: 'primary-button',
                            btnDisagreeClass: 'transparent-button',
                        },
                        agree: () => this.act(() => this.$axios.post(@js(route('admin.dam.upload-requests.reissue', ':id')).replace(':id', this.request.id)), true),
                        disagree: () => {},
                    });
                },

                async act(request, copyNewUrl = false) {
                    if (this.busy) return;

                    this.busy = true;

                    try {
                        const { data } = await request();

                        this.$emitter.emit('add-flash', { type: 'success', message: data.message });

                        if (copyNewUrl && data.request?.url) this.copy(data.request.url);

                        this.$emit('changed', data.request);
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error?.response?.data?.message || @js(trans('dam::app.admin.upload-requests.action-failed')) });
                    } finally {
                        this.busy = false;
                    }
                },

                copy(url) {
                    const done = () => this.$emitter.emit('add-flash', { type: 'success', message: @js(trans('dam::app.admin.upload-requests.copied')) });
                    const failed = () => this.$emitter.emit('add-flash', { type: 'error', message: @js(trans('dam::app.admin.upload-requests.copy-failed')) });

                    navigator.clipboard?.writeText ? navigator.clipboard.writeText(url).then(done, failed) : failed();
                },
            },
        });
    </script>
@endPushOnce
