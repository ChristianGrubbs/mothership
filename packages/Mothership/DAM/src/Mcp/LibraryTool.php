<?php

namespace Mothership\DAM\Mcp;

use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        'save'          => ['Save a local file into the library. With folder_id it becomes a NEW asset; with stack_asset_id it joins that asset\'s stack (cover unchanged), so an editable source file and its exports stay one asset: save the main display export (for example the PNG render) as a new asset first, then add the source (.blend, .psd ...) and other exports with stack_asset_id. Pass exactly one of folder_id or stack_asset_id. producer names the tool that made the file. provenance records how it was made (prompt, model, backend, generation_id, reference_asset_ids, vault_note, source_url) and notes anything else; both are returned by library_get_asset. Use a fresh request_id (UUID) per save and reuse it only to retry the same save. When the folder or stack already has that name, the result says renamed_from; nothing is ever replaced.', false, true],
        'update'        => ['Change one asset\'s title, or add or remove tags. Removing a tag records the removal, so automatic tagging does not put it back.', false, true],
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
        $fields = $this->fields();
        if (array_diff(array_keys($request->all()), array_keys($fields)) !== []) {
            return Response::error('Unknown tool argument. Use the published input schema.');
        }
        $rules = [];
        foreach ($fields as $key => $field) {
            $rules[$key] = $field[2];
            foreach ($field[3] ?? [] as $nested => $rule) {
                $rules[$key.'.'.$nested] = $rule;
            }
        }
        $arguments = $request->validate($rules);
        if (strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > 1024 * 1024) {
            return Response::error('Tool arguments exceed the 1 MiB limit.');
        }
        try {
            $result = $operations->execute(operation: $this->action, arguments: $arguments);
            MothershipLog::operation(operation: $this->action, ok: true);

            return Response::structured($result);
        } catch (HttpExceptionInterface $e) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'http_'.$e->getStatusCode());

            return Response::error(match ($e->getStatusCode()) {
                404     => 'Not found.',
                403     => 'The library account cannot do this (it lacks the permission, or the folder or asset is not open to it).',
                409     => $e->getMessage() !== '' ? $e->getMessage() : 'The request conflicts with an earlier save, or the asset was merged into another asset.',
                413     => 'The file exceeds the library limit.',
                422     => $e->getMessage() !== '' ? $e->getMessage() : 'The file or destination is invalid.',
                503     => 'The Mothership is busy finishing an earlier save. Try again shortly with the same request_id.',
                default => 'The operation could not be completed.',
            });
        } catch (ModelNotFoundException) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'not_found');

            return Response::error('Not found.');
        } catch (ContentUnavailable) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'content_unavailable');

            return Response::error('The stored bytes of this file cannot be read right now (the storage may be offline). Try again later.');
        } catch (LibraryFailure $e) {
            MothershipLog::operation(operation: $this->action, ok: false, reason: 'refused');

            return Response::error($e->getMessage());
        }
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
                        'reference_asset_ids' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Library assets used as references.'],
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
