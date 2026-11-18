<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\CompleteStylePressGeneration;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetFavoriteService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\GenerationAccess;
use Mothership\DAM\Services\GroqPromptEnhancer;
use Mothership\DAM\Services\HomeTelemetry;
use Mothership\DAM\Services\SharedHiggsfield;
use Mothership\DAM\Services\StylePressAccess;
use Mothership\DAM\Services\StylePressCatalog;
use Mothership\DAM\Services\StylePressPackageFiles;
use Mothership\DAM\Services\StylePressThumbnailDerivatives;
use RuntimeException;

class HomeStylePressController extends Controller
{
    private array $generationInputs = [];

    public function __construct(
        private StylePressCatalog $stylePress,
        private StylePressThumbnailDerivatives $thumbnailDerivatives,
    ) {}

    public function index()
    {
        $this->authorizeStylePress();

        return view('dam::home.stylepress', [
            'generationAvailable' => app(SharedHiggsfield::class)->enabled() || (filled(config('stylepress.higgsfield.api_key_id')) && filled(config('stylepress.higgsfield.api_key_secret'))),
            'catalog'             => $this->stylePress->data(),
            'generateUrl'         => route('admin.home.stylepress.generate'),
            'estimateUrl'         => route('admin.home.stylepress.estimate'),
            'balanceUrl'          => route('admin.home.stylepress.balance'),
            'catalogUrl'          => route('admin.home.stylepress.catalog'),
        ]);
    }

    public function catalog(): JsonResponse
    {
        $this->authorizeStylePress();

        return response()->json($this->stylePress->data());
    }

    public function balance(): JsonResponse
    {
        $this->authorizeStylePress();

        try {
            return response()->json(['ok' => true, 'balance' => $this->fetchHiggsfieldBalance()]);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function estimate(Request $request): JsonResponse
    {
        $this->authorizeStylePress();

        $validated = $request->validate([
            'style_slug'      => ['required', 'string', 'max:160'],
            'reuse_asset'     => ['nullable', 'integer'],
            'prompt'          => ['required', 'string', 'max:5000'],
            'aspect_ratio'    => ['nullable', 'in:auto,1:1,4:3,3:4,16:9,21:9,9:16,3:2,2:3'],
            'quality'         => ['nullable', 'in:low,medium,high'],
            'resolution'      => ['nullable', 'in:1k,2k,4k'],
            'references'      => ['nullable', 'array', 'max:8'],
            'references.*'    => ['uuid', 'distinct'],
            'enhanced_prompt' => ['nullable', 'string', 'max:30000'],
            'mode'            => ['nullable', 'in:create,restyle'],
            'model'           => ['nullable', 'in:gpt_image_2,gpt_image_2_5'],
            'variant'         => ['nullable', 'prohibited_unless:model,gpt_image_2_5', 'in:flare,sunburst'],
        ]);

        $style = $this->resolveStyle(validated: $validated);
        if ($style === null || ($style['generatable'] ?? true) === false) {
            return response()->json(['message' => 'Unknown StylePress style.'], 422);
        }

        $compiledPrompt = $this->stylePress->compile(style: $style, prompt: $validated['enhanced_prompt'] ?? $validated['prompt'], mode: $validated['mode'] ?? 'create');
        $aspect = $validated['aspect_ratio'] ?? $style['aspectRatio'] ?? '1:1';
        $this->prepareInputs(validated: $validated, style: $style, aspect: $aspect);

        try {
            return response()->json([
                'ok'              => true,
                'estimate'        => $this->estimateHiggsfield($compiledPrompt, config('stylepress.higgsfield.default_model'), $aspect),
                'compiled_prompt' => $compiledPrompt,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage(), 'compiled_prompt' => $compiledPrompt], 422);
        }
    }

    public function generate(Request $request): JsonResponse
    {
        $this->authorizeStylePress();

        $validated = $request->validate([
            'style_slug'      => ['required', 'string', 'max:160'],
            'reuse_asset'     => ['nullable', 'integer'],
            'prompt'          => ['required', 'string', 'max:5000'],
            'aspect_ratio'    => ['nullable', 'in:auto,1:1,4:3,3:4,16:9,21:9,9:16,3:2,2:3'],
            'quality'         => ['nullable', 'in:low,medium,high'],
            'resolution'      => ['nullable', 'in:1k,2k,4k'],
            'references'      => ['nullable', 'array', 'max:8'],
            'references.*'    => ['uuid', 'distinct'],
            'enhanced_prompt' => ['nullable', 'string', 'max:30000'],
            'mode'            => ['nullable', 'in:create,restyle'],
            'model'           => ['nullable', 'in:gpt_image_2,gpt_image_2_5'],
            'variant'         => ['nullable', 'prohibited_unless:model,gpt_image_2_5', 'in:flare,sunburst'],
        ]);

        $style = $this->resolveStyle(validated: $validated);
        if ($style === null || ($style['generatable'] ?? true) === false) {
            return response()->json(['message' => 'Unknown StylePress style.'], 422);
        }

        $aspect = $validated['aspect_ratio'] ?? $style['aspectRatio'] ?? '1:1';
        $this->prepareInputs(validated: $validated, style: $style, aspect: $aspect);
        $compiledPrompt = $this->stylePress->compile(style: $style, prompt: $validated['enhanced_prompt'] ?? $validated['prompt'], mode: $validated['mode'] ?? 'create');

        try {
            $estimate = $this->estimateHiggsfield($compiledPrompt, config('stylepress.higgsfield.default_model'), $aspect);
            $generation = $this->submitHiggsfield($compiledPrompt, config('stylepress.higgsfield.default_model'), $aspect);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'compiled_prompt' => $compiledPrompt], 422);
        }

        return response()->json([
            'ok'              => true,
            'style'           => ['slug' => $style['slug'], 'title' => $style['title']],
            'aspect_ratio'    => $estimate['aspect_ratio'] ?? $aspect,
            'estimate'        => $estimate,
            'compiled_prompt' => $compiledPrompt,
            'generation'      => $generation,
        ], 202);
    }

    public function generation(string $id): JsonResponse
    {
        $this->authorizeStylePress();
        $job = DB::table('dam_home_generations')->where('id', $id)->where('admin_id', auth('admin')->id())->first();
        abort_unless($job, 404);
        if ($job->inputs !== null) {
            return response()->json(['ok' => true, 'generation' => ['id' => $id, 'status' => $job->save_status === 'saved' ? 'completed' : ($job->status === 'completed' ? 'saving' : $job->status), 'url' => $job->save_status === 'saved' ? route('admin.home.stylepress.output', $id) : null, 'asset_id' => $job->asset_id, 'save_status' => $job->save_status, 'archive_status' => $this->archiveStatus(id: $id), 'message' => $job->save_error]])->header('Cache-Control', 'private, no-store');
        }
        if (! in_array($job->status, ['completed', 'failed', 'nsfw', 'canceled', 'cancelled'], true)) {
            try {
                if (str_starts_with($job->status_url, 'higgsfield-cli:')) {
                    $poll = ['http' => 200, 'body' => app(SharedHiggsfield::class)->poll(id: $job->request_id)];
                } else {
                    [, $keyId, $keySecret] = $this->higgsfieldConfig(null);
                    $poll = $this->higgsfieldRequest(method: 'GET', url: $job->status_url, payload: null, keyId: $keyId, keySecret: $keySecret);
                }
                if ($poll['http'] < 200 || $poll['http'] >= 300 || ! is_array($poll['body'])) {
                    throw new RuntimeException('Generation status is temporarily unavailable. Retry shortly.');
                }
                $state = strtolower((string) ($poll['body']['status'] ?? 'queued'));
                $job->status = in_array($state, ['queued', 'in_progress', 'running', 'completed', 'failed', 'nsfw', 'canceled', 'cancelled'], true) ? $state : 'queued';
                $job->result_url = $job->status === 'completed' ? $this->extractImageUrl($poll['body']) : null;
                DB::table('dam_home_generations')->where('id', $id)->update(['status' => $job->status, 'result_url' => $job->result_url, 'updated_at' => now()]);
                HomeTelemetry::record(event: 'generation.polled', ctx: ['admin_id' => auth('admin')->id(), 'widget' => 'stylepress', 'status' => $job->status]);
            } catch (RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }
        }

        return response()->json(['ok' => true, 'generation' => ['id' => $id, 'status' => $job->status, 'url' => $job->result_url]])->header('Cache-Control', 'private, no-store');
    }

    public function thumbnail(string $style)
    {
        $this->authorizeStylePress();
        $path = $this->stylePress->thumbnailPath(slug: $style);
        abort_unless($path, 404);

        $version = (string) ($this->stylePress->resolve(slug: $style)['catalog_version'] ?? 'unknown');
        $served = $this->thumbnailDerivatives->path(originalPath: $path, slug: $style, catalogVersion: $version);

        // The URL already carries ?v={catalogVersion}, so a fresh version is a new URL: cache
        // this one immutably for a year and rely on the ETag/304 round trip for the rest.
        $response = response()->file($served);
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');
        $response->setAutoEtag();
        $response->isNotModified(request());

        return $response;
    }

    public function source(string $style, int $attachment)
    {
        $this->authorizeStylePress();
        $path = $this->stylePress->attachmentPath(slug: $style, index: $attachment);
        abort_unless($path, 404);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $headers = ['Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff'];
        if (in_array($extension, ['md', 'txt', 'json', 'yaml', 'yml', 'log'], true)) {
            return response()->file($path, [...$headers, 'Content-Type'=>'text/plain; charset=utf-8']);
        }

        return response()->download($path, basename($path), $headers);
    }

    public function assets(Request $request): JsonResponse
    {
        $this->authorizeStylePress();
        $term = mb_strtolower(mb_substr(trim((string) $request->string('q')), 0, 100));
        $scope = in_array((string) $request->string('scope', 'all'), ['recent', 'favorites', 'all', 'folder'], true) ? (string) $request->string('scope', 'all') : 'all';
        $page = max(1, $request->integer('page', 1));
        $grammar = DB::connection()->getQueryGrammar();
        // Only the formats the reference endpoint accepts (PNG, JPEG, WebP) are offered, judged by the recorded
        // extension or MIME type; the reference endpoint still validates the bytes themselves on Add.
        $extension = $grammar->wrap('dam_assets.extension');
        $mime = $grammar->wrap('dam_assets.mime_type');
        $assets = app(AssetFavoriteService::class)->accessibleAssets()->withDisplayName()->where('dam_assets.file_type', 'image')
            ->where(fn ($query) => $query->whereRaw('LOWER('.$extension.') IN (?, ?, ?, ?)', ['png', 'jpg', 'jpeg', 'webp'])
                ->orWhereRaw('LOWER('.$mime.') IN (?, ?, ?, ?)', ['image/png', 'image/jpeg', 'image/jpg', 'image/webp']));
        $directory = null;
        if ($scope === 'folder') {
            // Folder browsing mirrors the library: direct members of one directory the admin may view.
            $directory = Directory::query()->find($request->integer('directory'));
            abort_unless($directory && app(DirectoryPermissionService::class)->canView($directory->id), 404, 'That folder is unavailable.');
            $assets->whereHas('directories', fn ($query) => $query->where('dam_directories.id', $directory->id));
        }
        if ($scope === 'favorites') {
            $assets->whereIn('dam_assets.id', DB::table('dam_asset_favorites')->where('admin_id', auth('admin')->id())->select('dam_asset_id'));
        }
        if ($scope === 'recent') {
            // Recent is a bounded window (uploads from the last 30 days); All is the paged full library.
            $assets->where('dam_assets.created_at', '>=', now()->subDays(30));
        }
        if ($term !== '') {
            // Literal search: escape LIKE metacharacters with "!" so names containing % or _ match themselves.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $fileName = $grammar->wrap('dam_assets.file_name');
            $tagName = $grammar->wrap('dam_tags.name');
            $assets->where(fn ($query) => $query->whereRaw('LOWER('.$fileName.") LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw('LOWER('.Asset::displayNameSql().") LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('tags', fn ($tags) => $tags->whereRaw('LOWER('.$tagName.") LIKE ? ESCAPE '!'", [$like])));
        }
        // Fetch one extra row so the client learns whether another page exists without a count query.
        $sort = (string) $request->string('sort', 'newest');
        if ($sort === 'name') {
            $assets->orderByRaw(Asset::displayNameSql().' asc')->orderBy('dam_assets.id');
        } else {
            $assets->orderByDesc('dam_assets.created_at')->orderByDesc('dam_assets.id');
        }
        $assets->limit(41);
        if (in_array($scope, ['all', 'folder'], true)) {
            $assets->offset(($page - 1) * 40);
        }
        $rows = $assets->get();
        $hasMore = in_array($scope, ['all', 'folder'], true) && $rows->count() > 40;

        return response()->json(['scope' => $scope, 'page' => $page, 'has_more' => $hasMore, 'directory' => $directory ? ['id' => $directory->id, 'name' => $directory->name] : null, 'assets' => $rows->take(40)->values()->map(fn ($a) => ['id' => $a->id, 'name' => $a->file_name, 'title' => $a->display_name, 'url' => route('admin.dam.file.thumbnail', ['path' => urlencode($a->path)])])]);
    }

    /**
     * The library folder tree for the reference browser, under StylePress access rather than the
     * directory ACL: roots (with one level of children) when no parent is given, else a page of children.
     */
    public function folders(Request $request): JsonResponse
    {
        $this->authorizeStylePress();
        $repository = app(DirectoryRepository::class);
        if (! $request->filled('parent')) {
            return response()->json(['data' => $repository->getDirectoryTreeOnly()]);
        }
        $parent = $request->integer('parent');
        abort_unless($parent > 0 && app(DirectoryPermissionService::class)->canView($parent), 404, 'That folder is unavailable.');
        $page = $repository->getShallowChildren($parent, null, max(0, $request->integer('offset', 0)), min(200, max(1, $request->integer('limit', 100))));

        return response()->json(['data' => $page['data']->values(), 'has_more' => $page['has_more']]);
    }

    public function reference(Request $request): JsonResponse
    {
        $this->authorizeStylePress();
        $data = $request->validate(['image' => ['required_without:asset_id', 'file', 'mimes:png,jpg,jpeg,webp', 'max:32768'], 'asset_id' => ['nullable', 'integer']]);
        if (isset($data['asset_id'])) {
            $asset = app(AssetFavoriteService::class)->findAccessible(assetId: $data['asset_id']);
            abort_unless($asset->file_type === 'image', 422);
            // The reference is the asset's current original, read through the seam (ADR 0009), never the
            // Library path. A missing/unreadable blob is a validation failure, not an unhandled exception;
            // the recorded size gates the read before any byte is loaded.
            $store = app(ContentStore::class);
            try {
                $object = $store->current($asset);
                $bytes = null;
                if ((int) $object->size <= 33554432) {
                    $stream = $store->open($object);
                    $bytes = stream_get_contents($stream);
                    fclose($stream);
                }
            } catch (\Throwable) {
                $bytes = null;
            }
            abort_unless(is_string($bytes), 422);
            $name = $asset->file_name;
        } else {
            $bytes = file_get_contents($request->file('image')->getRealPath());
            $name = $request->file('image')->getClientOriginalName();
        }
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) && $info[0] * $info[1] <= 32000000, 422, 'Use a PNG, JPEG or WebP of at most 32 megapixels.');
        $id = (string) Str::uuid();
        $path = 'stylepress/references/'.$id.image_type_to_extension($info[2]);
        abort_unless(Storage::disk('local')->put($path, $bytes), 500);
        DB::table('dam_stylepress_references')->insert(['id' => $id, 'admin_id' => auth('admin')->id(), 'path' => $path, 'name' => mb_substr($name, 0, 255), 'sha256' => hash('sha256', $bytes), 'asset_id' => isset($data['asset_id']) ? (int) $data['asset_id'] : null, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'name' => $name, 'url' => route('admin.home.stylepress.reference-file', $id)], 201);
    }

    public function referenceFile(string $id)
    {
        $this->authorizeStylePress();
        $row = DB::table('dam_stylepress_references')->where('id', $id)->where('admin_id', auth('admin')->id())->first();
        // A reference taken from the Library still names its asset: once that asset is hidden from the viewer or
        // stops being out for use, its copy answers like a missing reference (P2 S3 review). An upload, or a copy
        // whose asset is gone, stands alone.
        abort_unless($row && $this->referenceSourceOpen(row: $row), 404);

        return response()->file(Storage::disk('local')->path($row->path), ['Cache-Control' => 'private, no-store']);
    }

    public function output(string $id)
    {
        $this->authorizeStylePress();
        $row = DB::table('dam_home_generations')->where('id', $id)->where('admin_id', auth('admin')->id())->where('save_status', 'saved')->first();
        abort_unless($row, 404);
        $root = storage_path('app/private/stylepress/outbox/'.$id);
        try {
            $files = app(StylePressPackageFiles::class);
            $manifest = $files->read(manifestPath: $root.'/generation.json');
            $path = $files->resolve(root: $root, relative: $manifest['output']['path'] ?? '', sha256: $manifest['output']['sha256'] ?? '');
        } catch (RuntimeException|\JsonException|\TypeError $exception) {
            abort(404, 'The saved output is unavailable.');
        }

        return response()->file($path, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function enhance(Request $request): JsonResponse
    {
        $this->authorizeStylePress();
        $data = $request->validate(['reuse_asset' => 'nullable|integer', 'style_slug' => 'required|string|max:160', 'prompt' => 'required|string|max:5000', 'mode' => 'nullable|in:create,restyle', 'references' => 'nullable|array|max:8', 'references.*' => 'uuid|distinct']);
        $style = $this->resolveStyle(validated: $data);
        abort_unless($style && ($style['generatable'] ?? true), 422);
        $references = $this->ownedReferences(ids: $data['references'] ?? []);
        $preview = $this->stylePress->thumbnailPath(slug: $data['style_slug']);
        $paths = array_column($references, 'absolute_path');
        try {
            $context = mb_substr('STYLE: '.($style['title'] ?? '')."\nSTYLE LOOK AND INVARIANTS: ".($style['look'] ?? '')."\nThe full locked template will be applied to the accepted request by StylePress. Preserve the reference visual language; improve the user content brief only.", 0, 4000);
            $prompt = $data['prompt'];

            return response()->json(['ok' => true, ...$this->enhanceWith(prompt: $prompt, references: $paths, stylePreview: $preview, styleContext: $context)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Fast path first: Groq rewrites text briefs in a few seconds; the shared Higgsfield enhancer remains the
     * fallback (and the only path when Groq is not configured) so a Groq outage never removes the feature.
     *
     * @return array{enhanced_prompt: string, driver: string}
     */
    private function enhanceWith(string $prompt, array $references, ?string $stylePreview, string $styleContext): array
    {
        // Only the user's own references decide text-versus-vision routing on Groq; the style preview is art
        // direction, which the style context already describes. The Higgsfield enhancer keeps receiving it.
        $groq = app(GroqPromptEnhancer::class);
        if ($groq->configured()) {
            try {
                return ['enhanced_prompt' => $groq->enhance(prompt: $prompt, references: $references, styleContext: $styleContext), 'driver' => 'groq'];
            } catch (RuntimeException $e) {
                if (! config('stylepress.enhance.fallback', true)) {
                    throw $e;
                }
                HomeTelemetry::record(event: 'enhance.fallback', ctx: ['driver' => 'higgsfield', 'reason' => 'groq-failed']);
            }
        }

        return ['enhanced_prompt' => app(SharedHiggsfield::class)->enhance(prompt: $prompt, references: $stylePreview ? [...$references, $stylePreview] : $references, styleContext: $styleContext), 'driver' => 'higgsfield'];
    }

    private function archiveStatus(string $id): string
    {
        $path = storage_path('app/private/stylepress/outbox/'.$id.'/archive-receipt.json');
        $receipt = is_file($path) ? json_decode(file_get_contents($path), true) : null;

        return ($receipt['status'] ?? null) === 'archived' && filled($receipt['note_path'] ?? null) && filled($receipt['archived_at'] ?? null) ? 'archived' : 'pending';
    }

    private function resolveStyle(array $validated): ?array
    {
        if (! empty($validated['reuse_asset'])) {
            $manifest = app(GenerationAccess::class)->find(assetId: (int) $validated['reuse_asset'])->manifest;
            $style = $manifest['style'] ?? null;
            if (is_array($style) && filled($style['template'] ?? null) && ($style['slug'] ?? null) === $validated['style_slug']) {
                return $style;
            }

            return null;
        }

        return $this->stylePress->find(slug: $validated['style_slug']);
    }

    private function referenceSourceOpen(object $row): bool
    {
        return app(AssetAccessDecision::class)->sourceOpen(context: AccessContext::library(), sourceAssetId: $row->asset_id === null ? null : (int) $row->asset_id);
    }

    private function ownedReferences(array $ids): array
    {
        $references = [];
        foreach ($ids as $id) {
            $row = DB::table('dam_stylepress_references')->where('id', $id)->where('admin_id', auth('admin')->id())->first();
            // As referenceFile(): a reference whose Library source the viewer can no longer open is never sent on.
            abort_unless($row && $this->referenceSourceOpen(row: $row) && Storage::disk('local')->exists($row->path), 422, 'A reference image is unavailable. Upload it again.');
            $references[] = ['id' => $row->id, 'name' => $row->name, 'sha256' => $row->sha256, 'absolute_path' => Storage::disk('local')->path($row->path), 'role' => 'source', 'asset_id' => $row->asset_id ? (int) $row->asset_id : null];
        }

        return $references;
    }

    private function prepareInputs(array $validated, array $style, string $aspect): void
    {
        abort_unless(in_array($aspect, ['auto', '1:1', '4:3', '3:4', '16:9', '21:9', '9:16', '3:2', '2:3']), 422, 'The style default is unsupported. Choose an output ratio.');
        $refs = $this->ownedReferences(ids: $validated['references'] ?? []);
        abort_if(($validated['mode'] ?? 'create') === 'restyle' && $refs === [], 422, 'Restyle needs a source image.');
        $model = $validated['model'] ?? 'gpt_image_2';
        $variant = $model === 'gpt_image_2_5' ? ($validated['variant'] ?? 'flare') : null;
        $this->generationInputs = ['original_prompt' => $validated['prompt'], 'enhanced_prompt' => $validated['enhanced_prompt'] ?? null, 'style' => [...$style, 'version' => $style['version'] ?? $this->stylePress->data()['version'] ?? null], 'settings' => ['aspect_ratio' => $aspect, 'quality' => $validated['quality'] ?? 'high', 'resolution' => $validated['resolution'] ?? '2k', 'mode' => $validated['mode'] ?? 'create', 'model' => $model, 'variant' => $variant], 'references' => $refs];
    }

    private function authorizeStylePress(): void
    {
        app(StylePressAccess::class)->authorize();
    }

    private function estimateHiggsfield(string $prompt, ?string $model, string $aspectRatio): array
    {
        if (app(SharedHiggsfield::class)->enabled()) {
            return app(SharedHiggsfield::class)->estimate(prompt: $prompt, aspectRatio: $aspectRatio, quality: $this->generationInputs['settings']['quality'], resolution: $this->generationInputs['settings']['resolution'], references: array_column($this->generationInputs['references'], 'absolute_path'), model: $this->generationInputs['settings']['model'], variant: $this->generationInputs['settings']['variant']);
        }
        [$baseUrl, $keyId, $keySecret, $model] = $this->higgsfieldConfig($model);
        $prefix = trim((string) config('stylepress.higgsfield.estimate_prefix', '/estimate'), '/');
        if ($prefix === '') {
            throw new RuntimeException('Higgsfield estimate prefix is not configured.');
        }

        // The HTTP driver only routes the configured default model; 2.5 variants need the shared subscription CLI.
        if (($this->generationInputs['settings']['model'] ?? 'gpt_image_2') !== 'gpt_image_2') {
            throw new RuntimeException('GPT Image 2.5 requires the shared subscription connection. Choose GPT Image 2 · Standard.');
        }
        $payload = ['prompt' => $prompt, 'num_images' => 1, 'aspect_ratio' => $aspectRatio, ...array_diff_key($this->generationInputs['settings'], ['mode' => true, 'model' => true, 'variant' => true])];
        if ($this->generationInputs['references'] !== []) {
            throw new RuntimeException('Reference images require the shared subscription connection.');
        }
        $response = $this->higgsfieldRequest('POST', $baseUrl.'/'.$prefix.'/'.ltrim($model, '/'), $payload, $keyId, $keySecret);
        if (($response['http'] ?? 0) < 200 || ($response['http'] ?? 0) >= 300 || ! is_array($response['body'] ?? null)) {
            throw new RuntimeException('Higgsfield estimate request failed with HTTP '.($response['http'] ?? 0).'.');
        }

        return $response['body'];
    }

    private function fetchHiggsfieldBalance(): array
    {
        if (app(SharedHiggsfield::class)->enabled()) {
            return app(SharedHiggsfield::class)->account();
        }
        [$baseUrl, $keyId, $keySecret] = $this->higgsfieldConfig(null);
        $endpoint = trim((string) config('stylepress.higgsfield.balance_endpoint', ''));
        if ($endpoint === '') {
            throw new RuntimeException('Live balance endpoint is not configured for this Higgsfield API key. Estimates still use the documented /estimate endpoint.');
        }
        $response = $this->higgsfieldRequest('GET', $baseUrl.'/'.ltrim($endpoint, '/'), null, $keyId, $keySecret);
        if (($response['http'] ?? 0) < 200 || ($response['http'] ?? 0) >= 300 || ! is_array($response['body'] ?? null)) {
            throw new RuntimeException('Higgsfield balance request failed with HTTP '.($response['http'] ?? 0).'.');
        }

        return $response['body'];
    }

    private function submitHiggsfield(string $prompt, ?string $model, string $aspectRatio): array
    {
        $this->generationInputs['prompt'] = $prompt;
        if (app(SharedHiggsfield::class)->enabled()) {
            $requestId = app(SharedHiggsfield::class)->submit(prompt: $prompt, aspectRatio: $aspectRatio, quality: $this->generationInputs['settings']['quality'], resolution: $this->generationInputs['settings']['resolution'], references: array_column($this->generationInputs['references'], 'absolute_path'), model: $this->generationInputs['settings']['model'], variant: $this->generationInputs['settings']['variant']);

            return $this->saveGeneration(requestId: $requestId, statusUrl: 'higgsfield-cli:'.$requestId);
        }
        [$baseUrl, $keyId, $keySecret, $model] = $this->higgsfieldConfig($model);
        // The HTTP driver only routes the configured default model; 2.5 variants need the shared subscription CLI.
        if (($this->generationInputs['settings']['model'] ?? 'gpt_image_2') !== 'gpt_image_2') {
            throw new RuntimeException('GPT Image 2.5 requires the shared subscription connection. Choose GPT Image 2 · Standard.');
        }
        $payload = ['prompt' => $prompt, 'num_images' => 1, 'aspect_ratio' => $aspectRatio, ...array_diff_key($this->generationInputs['settings'], ['mode' => true, 'model' => true, 'variant' => true])];
        if ($this->generationInputs['references'] !== []) {
            throw new RuntimeException('Reference images require the shared subscription connection.');
        }
        $submit = $this->higgsfieldRequest(method: 'POST', url: $baseUrl.'/'.ltrim($model, '/'), payload: $payload, keyId: $keyId, keySecret: $keySecret);
        if ($submit['http'] < 200 || $submit['http'] >= 300 || ! is_array($submit['body'])) {
            throw new RuntimeException('Higgsfield generation request failed with HTTP '.$submit['http'].'.');
        }
        $body = $submit['body'];
        $requestId = (string) ($body['request_id'] ?? $body['id'] ?? '');
        if ($requestId === '' || strlen($requestId) > 255) {
            throw new RuntimeException('The generation service did not return a request ID.');
        }
        $statusUrl = (string) ($body['status_url'] ?? '');
        if (parse_url($statusUrl, PHP_URL_HOST) !== parse_url($baseUrl, PHP_URL_HOST)
            || parse_url($statusUrl, PHP_URL_SCHEME) !== parse_url($baseUrl, PHP_URL_SCHEME)) {
            $statusUrl = $baseUrl.'/requests/'.rawurlencode($requestId).'/status';
        }

        return $this->saveGeneration(requestId: $requestId, statusUrl: $statusUrl);
    }

    private function saveGeneration(string $requestId, string $statusUrl): array
    {
        $id = (string) Str::uuid();
        DB::table('dam_home_generations')->insert(['id' => $id, 'admin_id' => auth('admin')->id(), 'request_id' => $requestId, 'status_url' => $statusUrl, 'status' => 'queued', 'inputs' => json_encode($this->generationInputs), 'created_at' => now(), 'updated_at' => now()]);
        CompleteStylePressGeneration::dispatch($id)->onConnection('database')->onQueue('stylepress');
        HomeTelemetry::record(event: 'generation.submitted', ctx: ['admin_id' => auth('admin')->id(), 'widget' => 'stylepress']);

        return ['id' => $id, 'status' => 'queued', 'status_url' => route('admin.home.stylepress.generation', ['id' => $id])];
    }

    private function higgsfieldConfig(?string $model): array
    {
        $baseUrl = rtrim((string) config('stylepress.higgsfield.api_base_url'), '/');
        $keyId = trim((string) config('stylepress.higgsfield.api_key_id'));
        $keySecret = trim((string) config('stylepress.higgsfield.api_key_secret'));
        $model = $model ?: (string) config('stylepress.higgsfield.default_model');
        if ($baseUrl === '' || $keyId === '' || $keySecret === '') {
            throw new RuntimeException('StylePress Higgsfield API is not configured. Ask the administrator to connect the StylePress generation service.');
        }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || ! in_array($host, (array) config('stylepress.higgsfield.allowed_hosts', ['api.higgsfield.ai']), true)) {
            throw new RuntimeException('Configured Higgsfield API host is not allowed.');
        }

        return [$baseUrl, $keyId, $keySecret, $model];
    }

    private function higgsfieldRequest(string $method, string $url, ?array $payload, string $keyId, string $keySecret): array
    {
        try {
            $request = Http::acceptJson()->connectTimeout(5)->timeout(30)->withoutRedirecting()->withHeaders(['Authorization' => 'Key '.$keyId.':'.$keySecret]);
            $response = $request->send($method, $url, $payload === null ? [] : ['json' => $payload]);

            return ['http' => $response->status(), 'body' => $response->json()];
        } catch (ConnectionException) {
            throw new RuntimeException('The generation service is unavailable. Please retry shortly.');
        }
    }

    private function extractImageUrl(array $response): string
    {
        foreach (['url', 'result_url', 'image_url', 'output_url'] as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && $response[$key] !== '') {
                return $response[$key];
            }
        }
        foreach (['results', 'data', 'outputs', 'images'] as $list) {
            foreach (($response[$list] ?? []) as $item) {
                if (is_string($item) && preg_match('#^https?://#i', $item)) {
                    return $item;
                }
                if (is_array($item)) {
                    $found = $this->extractImageUrl($item);
                    if ($found !== '') {
                        return $found;
                    }
                }
            }
        }

        return '';
    }
}
