<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiBridgeUnavailable;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiNameSuggester;
use Mothership\DAM\AiIndex\StackMemberNamingContext;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Services\AssetRename;
use Mothership\DAM\Services\AssetRenameException;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\StackMemberRename;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Support\StackFileThumbnail;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * Reviewable names for current stack members; application compares the reviewed revision under the graph lock.
 */
class AiRenameController extends Controller
{
    use AssetAccessControl;

    public function suggest(Request $request, AiNameSuggester $suggester, AiBridgeClient $bridge): JsonResponse
    {
        // Not `exists`: a missing id answers like a hidden one.
        $request->validate(['id' => 'required|integer', 'file_id' => 'nullable|integer|min:1']);
        $id = (int) $request->input('id');

        abort_unless(bouncer()->hasPermission('dam.asset.rename'), 403, trans('dam::app.admin.permissions.unauthorized'));
        abort_unless(Asset::query()->whereKey($id)->exists() && $this->damCanAccessAsset($id), 404);
        $asset = Asset::query()->findOrFail($id);
        $file = null;
        if ($request->filled('file_id')) {
            abort_unless(bouncer()->hasPermission('dam.asset.files.index'), 403);
            $file = AssetFile::where('dam_asset_id', $id)->where('is_current', true)
                ->whereHas('attachment', fn ($query) => $query->where('dam_asset_id', $id))->findOrFail((int) $request->input('file_id'));
            app(RoleBuilder::class)->abortUnlessOwn(model: $asset, reason: 'own-only-label');
        }
        $eligibility = $file ? app(StackMemberNamingContext::class)->eligibility(asset: $asset, file: $file) : app(AiEligibility::class)->forAsset($asset);

        // Never trust the client's list: a file the AI cannot describe is refused here, not only left out of the dialog.
        if (in_array($eligibility['state'], [AiEligibility::UNSUPPORTED, AiEligibility::AWAITING_PREVIEW], true)) {
            return response()->json(['message' => $eligibility['message'], 'eligibility' => $eligibility], 422);
        }

        abort_unless($bridge->ready(), 503, trans('dam::app.admin.dam.asset.edit.ai.unavailable'));

        try {
            $suggestion = $suggester->suggest(asset: $asset, file: $file);
        } catch (AiBridgeUnavailable) {
            return response()->json(['message' => $file ? 'This member could not be described. Retry this row.' : trans('dam::app.admin.dam.asset.edit.ai.unavailable'), 'retryable' => true], 503);
        }

        // No description yet: the modal asks for one (the describe action) and comes back.
        if ($suggestion === null) {
            return response()->json(['id' => $id, 'needs_description' => true], 409);
        }

        return response()->json($suggestion);
    }

    public function members(Request $request): JsonResponse
    {
        $request->validate(['asset_ids' => 'required|array|min:1|max:500', 'asset_ids.*' => 'required|integer|min:1']);
        abort_unless(bouncer()->hasPermission('dam.asset.rename'), 403);
        abort_unless(bouncer()->hasPermission('dam.asset.files.index'), 403);
        $rows = [];
        foreach (array_unique($request->input('asset_ids')) as $id) {
            $asset = $this->damCanAccessAsset((int) $id) ? Asset::find($id) : null;
            if ($asset === null || app(RoleBuilder::class)->refusal(model: $asset, reason: 'own-only-label') !== null) {
                // Echo only the submitted identity: missing, hidden and non-editable assets are indistinguishable.
                $rows[] = ['asset_id' => (int) $id, 'attachment_id' => null, 'file_id' => null,
                    'file_name'       => null, 'extension' => null, 'thumbnail_url' => null, 'is_representative' => false,
                    'eligibility'     => ['state' => 'unavailable', 'message' => trans('dam::app.admin.explorer.ai-rename.not-eligible')]];

                continue;
            }
            foreach (Attachment::where('dam_asset_id', $id)->with('currentRevision.fileObject')->orderBy('position')->orderBy('id')->get() as $slot) {
                $file = $slot->currentRevision;
                if (! $file || (int) $file->dam_asset_id !== (int) $asset->id) {
                    continue;
                }
                $file->setRelation('attachment', $slot);
                $name = StackFileName::fileName(file: $file, asset: $asset);
                $rows[] = ['asset_id' => (int) $id, 'attachment_id' => (int) $slot->id, 'file_id' => (int) $file->id,
                    'file_name'       => $name, 'extension' => StackMemberNamingContext::extension(asset: $asset, file: $file), 'is_representative' => $slot->is_representative,
                    'thumbnail_url'   => StackFileThumbnail::urlFor(file: $file, asset: $asset),
                    'eligibility'     => app(StackMemberNamingContext::class)->eligibility(asset: $asset, file: $file)];
            }
        }

        return response()->json(['rows' => $rows]);
    }

    public function applyMember(Request $request, StackMemberRename $rename): JsonResponse
    {
        $data = $request->validate(['asset_id' => 'required|integer|min:1', 'attachment_id' => 'required|integer|min:1',
            'file_id'                          => 'required|integer|min:1', 'file_name' => AssetRename::FILE_NAME_RULE, 'title' => 'nullable|string|max:160']);
        abort_unless(bouncer()->hasPermission('dam.asset.rename'), 403);
        abort_unless(bouncer()->hasPermission('dam.asset.files.index'), 403);
        abort_unless(Asset::whereKey($data['asset_id'])->exists() && $this->damCanAccessAsset((int) $data['asset_id']), 404);
        try {
            return response()->json($rename->rename(asset: Asset::findOrFail($data['asset_id']), attachmentId: (int) $data['attachment_id'],
                expectedFileId: (int) $data['file_id'], fileName: $data['file_name'], title: $data['title'] ?? null));
        } catch (AssetRenameException $error) {
            return response()->json(['message' => 'This file name is already taken.', 'code' => $error->reason], 422);
        }
    }
}
