<?php

namespace Mothership\DAM\Mcp;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One `library_*` tool of the `mothership` server. The published input schema and the Laravel rules for each tool
 * come from one table (fields), so the two cannot drift apart.
 */
class LibraryTool extends Tool
{
    /** Producer and slug fields: lower-case words such as blender, image-gen or artcraft. */
    public const SLUG = '/^[a-z0-9][a-z0-9._-]{0,39}$/';

    /** action => [description, read-only, idempotent] */
    public const ACTIONS = [
        'search'        => ['Find library assets by file name, title or tag (either case), optionally of one extension, kind (image, video, audio, document, 3d and so on), exact tag, folder or producer (the tool that saved it, such as blender). Returns asset_id, title, file_name, extension, kind, size, tags, producer and created_at. Follow next_after_id for further pages.', true, true],
        'list_folders'  => ['List library folders you can open. Narrow with parent_id (direct subfolders) or name (contains, either case). Pass next_after_id as after_id for further pages.', true, true],
        'create_folder' => ['Create a subfolder under parent_id. If a folder of that name (either case) is already there, it is returned instead (created: false), so a retry never makes a second one.', false, true],
        'get_asset'     => ['Read one asset: title, file name, kind, size, sha256 and pixel size of its main file, folders, tags, how many files its stack holds, whether a 1080p video proxy exists, and the producer, provenance and notes recorded when a tool saved it.', true, true],
        'list_stack'    => ['List the files stacked in one asset (cover export, editable source, other exports) with file_id, label, extension, size, sha256, is_cover and producer. Pass extension (and name when the stack holds several documents) to find one file; follow next_after_id. Then copy one out with library_fetch and its file_id.', true, true],
        'fetch'         => ['Copy one asset file to a local folder to work on: the main file, one stack file (file_id), or the 1080p web proxy of a video (proxy: true; use it for 4K or very large footage). The copy is checked against its sha256 and returned as path; changing it never changes the library. An identical file already at that name is reused; any other is never overwritten (the copy is named name-2 and so on). dest_dir defaults to the server\'s fetch folder.', false, true],
        'save'          => ['Save a local file into the library. With folder_id it becomes a NEW asset; with stack_asset_id it joins that asset\'s stack (cover unchanged), so an editable source file and its exports stay one asset: save the main display export (for example the PNG render) as a new asset first, then add the source (.blend, .psd ...) and other exports with stack_asset_id. Pass exactly one of folder_id or stack_asset_id. producer names the tool that made the file. provenance records how it was made (prompt, model, backend, generation_id, reference_asset_ids, vault_note, source_url) and notes anything else; both are returned by library_get_asset. Each reference_asset_ids entry also becomes a derived-from link from the saved asset to that reference (returned as derived_from), so made work links back to what it came from. Use a fresh request_id (UUID) per save and reuse it only to retry the same save. When the folder or stack already has that name, the result says renamed_from; nothing is ever replaced.', false, true],
        'update'        => ['Change one asset\'s title, or add or remove tags. Removing a tag records the removal, so automatic tagging does not put it back.', false, true],
        'vectorize'     => ['Convert a raster image (PNG, JPEG, WebP, BMP, GIF or TIFF, up to 20 MiB and 33,554,432 pixels) to vector SVG and PDF with the vectorizer.ai integration. Use it instead of tracing by hand. With asset_id and format (svg or pdf) the result is filed onto that library asset as an output file (needs edit rights on it); with local_path it is a one-off whose SVG and PDF are kept for you and filed nowhere. Pass exactly one of asset_id or local_path. It is queued: poll library_vectorization with the returned id until status is completed or failed (usually under a minute). Use a fresh request_id (UUID) per conversion and reuse it only to retry the same one, so a retry never pays twice.', false, true],
        'vectorization' => ['Read one conversion started with library_vectorize: status (queued, processing, completed or failed), kind (asset or one_off) and, for a completed filed one, output.asset_id and output.file_id (copy it out with library_fetch and that file_id). Pass format (svg or pdf) once it is completed to copy that result to a local folder: it is returned as path with its sha256. dest_dir defaults to the server\'s fetch folder; an identical file already there is reused and any other is never overwritten.', false, true],
    ];

    public function __construct(private string $action)
    {
        $this->name = 'library_'.$action;
        $this->description = self::ACTIONS[$action][0];
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::ACTIONS);
    }

    public function toArray(): array
    {
        $properties = [];
        $required = [];
        foreach ($this->fields() as $key => [$schema, $isRequired]) {
            $properties[$key] = $schema;
            if ($isRequired) {
                $required[] = $key;
            }
        }
        [, $readOnly, $idempotent] = self::ACTIONS[$this->action];

        // @phpstan-ignore return.type
        return $this->mergeMeta([
            'name'        => $this->name,
            'description' => $this->description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false],
            'annotations' => [
                'readOnlyHint'    => $readOnly,
                'destructiveHint' => false,
                'idempotentHint'  => $idempotent,
                'openWorldHint'   => false,
            ],
        ]);
    }

    public function handle(Request $request, LibraryOperations $operations): Response|ResponseFactory
    {
        try {
            return Response::structured($this->perform(
                input: $request->all(),
                run: fn (array $arguments): array => $operations->execute(operation: $this->action, arguments: $arguments),
            ));
        } catch (LibraryRefusal $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * The tool's checks and wording, shared by the stdio server (handle) and the REST route (LibraryToolController):
     * unknown arguments, the field rules, the 1 MiB cap, then $run with the validated arguments. A refusal is thrown
     * as LibraryRefusal carrying the text the agent reads; a rule failure as the ValidationException itself.
     *
     * @template T
     *
     * @param  array<array-key, mixed>  $input
     * @param  callable(array<string, mixed>): T  $run
     * @return T
     *
     * @throws ValidationException
     * @throws LibraryRefusal
     */
    public function perform(array $input, callable $run, string $via = 'stdio'): mixed
    {
        if (array_diff(array_keys($input), array_keys($this->fields())) !== []) {
            throw new LibraryRefusal(message: 'Unknown tool argument. Use the published input schema.', status: 422);
        }
        $arguments = Validator::validate($input, $this->rules());
        if (strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > 1024 * 1024) {
            throw new LibraryRefusal(message: 'Tool arguments exceed the 1 MiB limit.', status: 413);
        }
        try {
            $result = $run($arguments);
            MothershipLog::operation(operation: $this->action, ok: true, via: $via);

            return $result;
        } catch (HttpExceptionInterface $e) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'http_'.$e->getStatusCode(), via: $via);

            throw new LibraryRefusal(status: $e->getStatusCode(), message: match ($e->getStatusCode()) {
                404     => 'Not found.',
                403     => 'The library account cannot do this (it lacks the permission, or the folder or asset is not open to it).',
                409     => $e->getMessage() !== '' ? $e->getMessage() : 'The request conflicts with an earlier save, or the asset was merged into another asset.',
                413     => 'The file exceeds the library limit.',
                422     => $e->getMessage() !== '' ? $e->getMessage() : 'The file or destination is invalid.',
                503     => str_starts_with($this->action, 'vectoriz') && $e->getMessage() !== '' ? $e->getMessage() : 'The Mothership is busy finishing an earlier save. Try again shortly with the same request_id.',
                default => 'The operation could not be completed.',
            });
        } catch (ModelNotFoundException) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'not_found', via: $via);

            throw new LibraryRefusal(message: 'Not found.', status: 404);
        } catch (ContentUnavailable) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'content_unavailable', via: $via);

            throw new LibraryRefusal(message: 'The stored bytes of this file cannot be read right now (the storage may be offline). Try again later.', status: 503);
        } catch (LibraryFailure $e) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'refused', via: $via);

            throw new LibraryRefusal(message: $e->getMessage(), status: 422);
        }
    }

    /** @return array<string, list<mixed>> the Laravel rules of fields(), nested keys spelled out */
    private function rules(): array
    {
        $rules = [];
        foreach ($this->fields() as $key => $field) {
            $rules[$key] = $field[2];
            foreach ($field[3] ?? [] as $nested => $rule) {
                $rules[$key.'.'.$nested] = $rule;
            }
        }

        return $rules;
    }

    /**
     * key => [JSON schema, required, Laravel rules, nested rules (key suffix => rules)]
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: list<mixed>, 3?: array<string, list<mixed>>}>
     */
    private function fields(): array
    {
        $assetId = ['asset_id' => [['type' => 'integer', 'minimum' => 1], true, ['required', 'integer', 'min:1']]];
        $afterId = ['after_id' => [['type' => 'integer', 'minimum' => 0, 'description' => 'The previous page\'s next_after_id; stop when it is null.'], false, ['sometimes', 'integer', 'min:0']]];
        $extension = ['extension' => [['type' => 'string', 'maxLength' => 16, 'description' => 'Only files of this type, such as png, blend or mp4.'], false, ['sometimes', 'string', 'max:16', 'regex:/^\.?[A-Za-z0-9]{1,15}$/']]];
        $tagList = fn (string $description): array => [['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'maxLength' => 100], 'description' => $description], false, ['sometimes', 'array', 'max:50'], ['*' => ['string', 'max:100']]];

        return match ($this->action) {
            'search' => [
                'query'     => [['type' => 'string', 'maxLength' => 160, 'description' => 'Text in the file name, title or a tag name, either case.'], false, ['sometimes', 'string', 'max:160']],
                ...$extension,
                'kind'      => [['type' => 'string', 'maxLength' => 32, 'description' => 'Only assets of this kind, such as image, video, audio, document or 3d.'], false, ['sometimes', 'string', 'max:32']],
                'tag'       => [['type' => 'string', 'maxLength' => 100, 'description' => 'Only assets carrying this exact tag (either case).'], false, ['sometimes', 'string', 'max:100']],
                'folder_id' => [['type' => 'integer', 'minimum' => 1, 'description' => 'Only assets filed directly in this folder (from library_list_folders).'], false, ['sometimes', 'integer', 'min:1']],
                'producer'  => [['type' => 'string', 'maxLength' => 40, 'description' => 'Only assets saved by this tool through library_save, such as blender.'], false, ['sometimes', 'string', 'max:40']],
                'limit'     => [['type' => 'integer', 'minimum' => 1, 'maximum' => LibraryOperations::SEARCH_MAX, 'default' => LibraryOperations::SEARCH_PAGE], false, ['sometimes', 'integer', 'min:1', 'max:'.LibraryOperations::SEARCH_MAX]],
                ...$afterId,
            ],
            'list_folders' => [
                'parent_id' => [['type' => 'integer', 'minimum' => 1, 'description' => 'Only the direct subfolders of this folder.'], false, ['sometimes', 'integer', 'min:1']],
                'name'      => [['type' => 'string', 'maxLength' => 255, 'description' => 'Only folders whose name contains this text, either case.'], false, ['sometimes', 'string', 'max:255']],
                ...$afterId,
            ],
            'create_folder' => [
                'parent_id' => [['type' => 'integer', 'minimum' => 1, 'description' => 'The folder to create it in (from library_list_folders).'], true, ['required', 'integer', 'min:1']],
                'name'      => [['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'The new folder\'s name, without / \\ : * ? " < > |.'], true, ['required', 'string', 'max:255', 'regex:/^[^\\/\\\\:\*\?"<>\|]+$/', 'not_regex:/^\s*$/']],
            ],
            'get_asset'  => $assetId,
            'list_stack' => [
                ...$assetId,
                ...$extension,
                'name' => [['type' => 'string', 'maxLength' => 200, 'description' => 'Only files whose label contains this text, either case.'], false, ['sometimes', 'string', 'max:200']],
                ...$afterId,
            ],
            'fetch' => [
                ...$assetId,
                'file_id'  => [['type' => 'integer', 'minimum' => 1, 'description' => 'One stacked file of this asset, from library_list_stack. Omit for the asset\'s main file.'], false, ['sometimes', 'integer', 'min:1']],
                'proxy'    => [['type' => 'boolean', 'default' => false, 'description' => 'True copies the 1080p H.264 web proxy of a video instead of the original. Omit file_id when given.'], false, ['sometimes', 'boolean']],
                'dest_dir' => [['type' => 'string', 'maxLength' => 1024, 'description' => 'Absolute path of the local folder to copy into; created when missing. Never a folder inside The Mothership\'s own storage.'], false, ['sometimes', 'string', 'max:1024']],
            ],
            'save' => [
                'local_path'     => [['type' => 'string', 'maxLength' => 1024, 'description' => 'Absolute path of the finished local file to save.'], true, ['required', 'string', 'max:1024']],
                'folder_id'      => [['type' => 'integer', 'minimum' => 1, 'description' => 'Save as a NEW asset in this folder. Omit when stack_asset_id is given.'], false, ['required_without:stack_asset_id', 'prohibits:stack_asset_id', 'integer', 'min:1']],
                'stack_asset_id' => [['type' => 'integer', 'minimum' => 1, 'description' => 'Add the file to this existing asset\'s stack instead (cover unchanged). Omit when folder_id is given.'], false, ['required_without:folder_id', 'integer', 'min:1']],
                'name'           => [['type' => 'string', 'maxLength' => 200, 'description' => 'The file name to save it under, with its extension. Default: the local file\'s own name.'], false, ['sometimes', 'string', 'max:200']],
                'title'          => [['type' => 'string', 'maxLength' => 255, 'description' => 'A new asset\'s title.'], false, ['sometimes', 'string', 'max:255']],
                'tags'           => $tagList('Tags to add to the new asset or, in stack mode, to the stack\'s asset.'),
                'notes'          => [['type' => 'string', 'maxLength' => 4000, 'description' => 'Anything worth keeping about this file, returned by library_get_asset.'], false, ['sometimes', 'string', 'max:4000']],
                'producer'       => [[
                    'type'                 => 'object',
                    'description'          => 'The tool that made the file.',
                    'properties'           => [
                        'tool'    => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{0,39}$', 'description' => 'Such as blender, artcraft, image-gen, higgsfield or ffmpeg.'],
                        'app'     => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{0,39}$', 'description' => 'The app within that tool, such as photocraft.'],
                        'version' => ['type' => 'string', 'maxLength' => 64],
                    ],
                    'required'             => ['tool'],
                    'additionalProperties' => false,
                ], true, ['required', 'array:tool,app,version'], [
                    'tool'    => ['required', 'string', 'regex:'.self::SLUG],
                    'app'     => ['sometimes', 'string', 'regex:'.self::SLUG],
                    'version' => ['sometimes', 'string', 'max:64'],
                ]],
                'provenance'     => [[
                    'type'                 => 'object',
                    'description'          => 'How the file was made.',
                    'properties'           => [
                        'prompt'              => ['type' => 'string', 'maxLength' => 8000],
                        'model'               => ['type' => 'string', 'maxLength' => 200],
                        'backend'             => ['type' => 'string', 'maxLength' => 200],
                        'generation_id'       => ['type' => 'string', 'maxLength' => 200],
                        'reference_asset_ids' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Library assets used as references. The save links the saved asset (in stack mode, the stack\'s asset) derived-from each one, in the same step; every id must be an asset this account can see, or nothing is saved.'],
                        'vault_note'          => ['type' => 'string', 'maxLength' => 500, 'description' => 'The note that records the work.'],
                        'source_url'          => ['type' => 'string', 'maxLength' => 2000],
                    ],
                    'additionalProperties' => false,
                ], false, ['sometimes', 'array:prompt,model,backend,generation_id,reference_asset_ids,vault_note,source_url'], [
                    'prompt'                => ['sometimes', 'string', 'max:8000'],
                    'model'                 => ['sometimes', 'string', 'max:200'],
                    'backend'               => ['sometimes', 'string', 'max:200'],
                    'generation_id'         => ['sometimes', 'string', 'max:200'],
                    'reference_asset_ids'   => ['sometimes', 'array', 'max:50'],
                    'reference_asset_ids.*' => ['integer', 'min:1'],
                    'vault_note'            => ['sometimes', 'string', 'max:500'],
                    'source_url'            => ['sometimes', 'string', 'max:2000'],
                ]],
                'evidence'       => [['type' => 'object', 'description' => 'Optional checks or measurements that back the result, stored with the save as given.'], false, ['sometimes', 'array']],
                'request_id'     => [['type' => 'string', 'format' => 'uuid', 'description' => 'A fresh UUID for each new save; reuse it only to retry that same save.'], true, ['required', 'uuid']],
            ],
            'vectorize' => [
                'asset_id'   => [['type' => 'integer', 'minimum' => 1, 'description' => 'File the result onto this library asset (its original must be a supported raster image). Omit when local_path is given.'], false, ['required_without:local_path', 'prohibits:local_path', 'integer', 'min:1']],
                'local_path' => [['type' => 'string', 'maxLength' => 1024, 'description' => 'Absolute path of a local raster image for a one-off conversion. Omit when asset_id is given.'], false, ['required_without:asset_id', 'string', 'max:1024']],
                'format'     => [['type' => 'string', 'enum' => ['svg', 'pdf'], 'description' => 'The format filed onto the asset; required with asset_id. A one-off always makes both.'], false, ['required_with:asset_id', 'string', 'in:svg,pdf']],
                'request_id' => [['type' => 'string', 'format' => 'uuid', 'description' => 'A fresh UUID for each new conversion; reuse it only to retry that same conversion.'], true, ['required', 'uuid']],
            ],
            'vectorization' => [
                'id'       => [['type' => 'string', 'format' => 'uuid', 'description' => 'The id library_vectorize returned.'], true, ['required', 'uuid']],
                'format'   => [['type' => 'string', 'enum' => ['svg', 'pdf'], 'description' => 'Copy this completed result to dest_dir. Omit to read the status only.'], false, ['sometimes', 'string', 'in:svg,pdf']],
                'dest_dir' => [['type' => 'string', 'maxLength' => 1024, 'description' => 'Absolute path of the local folder to copy into; created when missing. Never a folder inside The Mothership\'s own storage.'], false, ['sometimes', 'string', 'max:1024']],
            ],
            'update' => [
                ...$assetId,
                'title'       => [['type' => 'string', 'minLength' => 1, 'maxLength' => 255], false, ['sometimes', 'string', 'max:255']],
                'tags_add'    => $tagList('Tags to add.'),
                'tags_remove' => $tagList('Tags to remove.'),
            ],
            default => throw new \LogicException('Unknown library action.'),
        };
    }
}
