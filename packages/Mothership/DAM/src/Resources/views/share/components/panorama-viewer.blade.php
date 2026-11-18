{{--
    Interactive 360 viewer for the public share page.

    The admin viewer (asset/preview-modal/pano360) is a mixin wired into the
    authenticated preview modal: build/poll workflow, proxy selection, video
    player state. None of that exists for an anonymous viewer, who only ever
    has the shared original, so this is the sphere renderer on its own.
--}}
<script type="text/x-template" id="v-dam-public-panorama-template">
    <div ref="container" class="relative w-full h-full overflow-hidden bg-black" data-dam-share-360-viewer>
        <canvas
            v-show="!flat"
            ref="canvas"
            class="block w-full h-full cursor-grab active:cursor-grabbing touch-none"
            aria-label="@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.drag-hint')"
        ></canvas>

        <img
            v-if="flat"
            :src="src"
            :alt="alt"
            class="block w-full h-full object-contain"
        />

        <div
            v-if="error"
            class="absolute top-12 left-1/2 -translate-x-1/2 z-20 max-w-xl rounded-lg bg-amber-950/90 px-4 py-2 text-xs text-amber-100 shadow"
            role="status"
        >@{{ error }}</div>

        <div class="absolute top-3 left-3 z-20 flex items-center gap-2 pointer-events-none">
            <span class="rounded-full bg-primary-600/95 px-3 py-1 text-xs font-bold text-white shadow">
                @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.grid-badge')
            </span>
            <span v-if="!flat" class="rounded-full bg-black/65 px-3 py-1 text-xs text-white/90">
                @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.drag-hint')
            </span>
        </div>

        <div class="absolute bottom-0 inset-x-0 z-20 flex flex-wrap items-center gap-3 bg-gradient-to-t from-black/90 to-transparent px-4 pb-4 pt-10 text-white">
            <button type="button" class="rounded-lg border border-white/40 px-3 py-1.5 text-xs font-semibold hover:bg-white/10" @click="toggleFlat">
                <span v-if="flat">@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.sphere-view')</span>
                <span v-else>@lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.flat-view')</span>
            </button>
            <button type="button" class="rounded-lg border border-white/40 px-3 py-1.5 text-xs font-semibold hover:bg-white/10" @click="toggleFullscreen">
                @lang('dam::app.admin.dam.asset.edit.preview-modal.pano360.fullscreen')
            </button>
        </div>
    </div>
</script>

<script type="module">
    // WebGL objects must never enter Vue's deep reactive proxy graph.
    const panoramaRuntimes = new WeakMap();

    app.component('v-dam-public-panorama', {
        template: '#v-dam-public-panorama-template',

        props: {
            src: { type: String, required: true },
            alt: { type: String, default: '' },
        },

        data() {
            return { flat: false, error: null };
        },

        mounted() { this.start(); },

        beforeUnmount() { this.stop(); },

        methods: {
            stop() {
                const runtime = panoramaRuntimes.get(this);
                if (!runtime) return;

                panoramaRuntimes.delete(this);
                runtime.cancelled = true;
                runtime.renderer?.setAnimationLoop(null);
                runtime.resizeObserver?.disconnect();
                runtime.cleanup?.();
                runtime.texture?.dispose();
                runtime.material?.dispose();
                runtime.geometry?.dispose();
                runtime.renderer?.dispose();
            },

            async start() {
                this.stop();

                const runtime = { cancelled: false };
                panoramaRuntimes.set(this, runtime);

                try {
                    const THREE = await window.loadDamThree();
                    if (runtime.cancelled) return;

                    const canvas = this.$refs.canvas;
                    const container = this.$refs.container;

                    const renderer = runtime.renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
                    const scene = new THREE.Scene();
                    const camera = new THREE.PerspectiveCamera(70, 1, 0.1, 300);
                    const geometry = runtime.geometry = new THREE.SphereGeometry(100, 64, 40);
                    geometry.scale(-1, 1, 1);

                    const texture = await new THREE.TextureLoader().loadAsync(this.src);
                    if (runtime.cancelled) { texture.dispose(); return; }

                    runtime.texture = texture;
                    texture.colorSpace = THREE.SRGBColorSpace;
                    const material = runtime.material = new THREE.MeshBasicMaterial({ map: texture });
                    scene.add(new THREE.Mesh(geometry, material));

                    let longitude = 0;
                    let latitude = 0;
                    let dragging = false;
                    let lastX = 0;
                    let lastY = 0;

                    const resize = () => {
                        const width = Math.max(1, container.clientWidth);
                        const height = Math.max(1, container.clientHeight);
                        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
                        renderer.setSize(width, height, false);
                        camera.aspect = width / height;
                        camera.updateProjectionMatrix();
                    };
                    const down = (event) => {
                        dragging = true;
                        lastX = event.clientX;
                        lastY = event.clientY;
                        canvas.setPointerCapture?.(event.pointerId);
                    };
                    const move = (event) => {
                        if (!dragging) return;
                        longitude -= (event.clientX - lastX) * 0.12;
                        latitude = Math.max(-85, Math.min(85, latitude + (event.clientY - lastY) * 0.12));
                        lastX = event.clientX;
                        lastY = event.clientY;
                    };
                    const up = () => { dragging = false; };
                    const wheel = (event) => {
                        event.preventDefault();
                        camera.fov = Math.max(30, Math.min(90, camera.fov + event.deltaY * 0.04));
                        camera.updateProjectionMatrix();
                    };

                    canvas.addEventListener('pointerdown', down);
                    canvas.addEventListener('pointermove', move);
                    canvas.addEventListener('pointerup', up);
                    canvas.addEventListener('pointercancel', up);
                    canvas.addEventListener('wheel', wheel, { passive: false });
                    runtime.cleanup = () => {
                        canvas.removeEventListener('pointerdown', down);
                        canvas.removeEventListener('pointermove', move);
                        canvas.removeEventListener('pointerup', up);
                        canvas.removeEventListener('pointercancel', up);
                        canvas.removeEventListener('wheel', wheel);
                    };

                    runtime.resizeObserver = new ResizeObserver(resize);
                    runtime.resizeObserver.observe(container);
                    resize();

                    const target = new THREE.Vector3();
                    renderer.setAnimationLoop(() => {
                        target.setFromSphericalCoords(
                            1,
                            THREE.MathUtils.degToRad(90 - latitude),
                            THREE.MathUtils.degToRad(longitude),
                        );
                        camera.lookAt(target);
                        renderer.render(scene, camera);
                    });
                } catch (error) {
                    if (runtime.cancelled) return;

                    this.stop();
                    this.flat = true;
                    this.error = @js(trans('dam::app.admin.dam.asset.edit.preview-modal.pano360.three-failed'));
                }
            },

            toggleFlat() {
                this.flat = !this.flat;
                this.error = null;

                if (this.flat) {
                    this.stop();
                } else {
                    this.$nextTick(() => this.start());
                }
            },

            toggleFullscreen() {
                if (document.fullscreenElement) document.exitFullscreen();
                else this.$refs.container?.requestFullscreen?.();
            },
        },
    });
</script>
