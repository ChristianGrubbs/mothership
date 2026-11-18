<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\RequestId;
use Mothership\DAM\Support\StackFileName;
use Throwable;

/**
 * The AI description of one stacked file that is not the cover (the cover is the asset row; dam_ai_index describes it).
 * A member has no asset row, so its description is one row of dam_ai_member_descriptions keyed by (asset, file object)
 * of the revision that was described, and it is served only while that object is still the member's current revision
 * and the stored sha equals the object's sha256: new bytes, a promotion or a detach can never show an old caption.
 *
 * Describing is one bounded synchronous bridge call (the rename dialog's member path does the same). Nothing is
 * locked while the bridge works: the revision is read first, the picture is described, and only then a short
 * transaction takes the graph lock (asset, slots, files, in id order, as StackMemberRename does), re-checks that the
 * very same revision is still a current member of this asset, and writes. Anything else answers 409 and writes nothing.
 */
final class MemberDescriptions
{
    public function __construct(
        private StackMemberNamingContext $naming,
        private MediaSampler $sampler,
        private AiBridgeClient $bridge,
        private ContentStore $contents,
    ) {}

    /** The current revision of a non-cover slot of this asset; 404 for anything else (another asset's id, a past revision, the cover). */
    public static function resolve(Asset $asset, int $fileId): AssetFile
    {
        $file = AssetFile::query()->where('dam_asset_id', $asset->id)->whereKey($fileId)->where('is_current', true)
            ->whereHas('attachment', fn ($query) => $query->where('dam_asset_id', $asset->id)->where('is_representative', false))
            ->with(['fileObject', 'attachment'])->firstOrFail();

        abort_unless($file->fileObject, 404);

        return $file;
    }

    /** The saved description of these exact bytes, or null. */
    public function stored(AssetFile $file): ?object
    {
        $object = $file->fileObject;

        if (! $object) {
            return null;
        }

        $row = DB::table('dam_ai_member_descriptions')->where('asset_id', $file->dam_asset_id)->where('file_object_id', $file->file_object_id)->first();

        return $row && filled($row->caption) && $row->content_sha256 === $object->sha256 ? $row : null;
    }

    /** @return array<string, mixed> same shape as the cover's card, `eligibility.scope` is `member` */
    public function card(Asset $asset, AssetFile $file): array
    {
        $row = $this->stored($file);
        $reachable = $this->bridge->health();
        $canDescribe = bouncer()->hasPermission('dam.asset.tag') && app(RoleBuilder::class)->refusal($asset) === null;
        // SVG is not described anywhere (the cover's rule 4); a member that is SVG is not "waiting for a preview" either.
        $state = StackMemberNamingContext::extension(asset: $asset, file: $file) === 'svg'
            ? AiEligibility::UNSUPPORTED
            : $this->naming->eligibility($asset, $file)['state'];

        $key = match (true) {
            $row !== null                              => null,
            $state === AiEligibility::UNSUPPORTED      => 'unsupported',
            $state === AiEligibility::AWAITING_PREVIEW => 'awaiting',
            $reachable === false                       => filled(config('ai-index.api')) ? 'unreachable' : 'not-configured',
            default                                    => null,
        };
        $serviceDown = $row === null && $state === AiEligibility::ELIGIBLE && $reachable === false;

        return [
            'ai' => $row === null ? null : [
                'caption'  => $row->caption,
                'keywords' => json_decode((string) $row->keywords, true) ?: [],
                'tags'     => [],
            ],
            'available'   => $reachable,
            'can_review'  => $canDescribe,
            'file'        => ['id' => (int) $file->id, 'name' => StackFileName::fileName(file: $file, asset: $asset)],
            'eligibility' => [
                'state'        => $row !== null ? AiEligibility::ELIGIBLE : ($serviceDown ? AiEligibility::SERVICE_UNAVAILABLE : $state),
                'reason'       => $row !== null ? 'described' : null,
                'message'      => match ($key) {
                    null       => null,
                    // A stacked file is described when a person asks, so the cover's "will start automatically" does not apply.
                    'awaiting' => trans('dam::app.admin.dam.asset.edit.ai.member.awaiting'),
                    default    => trans('dam::app.admin.dam.asset.edit.ai.state.'.$key),
                },
                'can_generate' => $row === null && $state === AiEligibility::ELIGIBLE && ! $serviceDown,
                'can_rename'   => false,
                'can_retry'    => false,
                'will_retry'   => false,
                'scope'        => 'member',
            ],
        ];
    }

    /**
     * Describes the member's current bytes and saves the description. Throws AiBridgeUnavailable when the picture cannot be
     * described (the caller says so and offers a retry; nothing is saved); aborts 409 when the revision moved meanwhile.
     *
     * @return array<string, mixed> the card, now with the description
     */
    public function describe(Asset $asset, int $fileId): array
    {
        $file = self::resolve(asset: $asset, fileId: $fileId);
        $objectId = (int) $file->file_object_id;
        $sha = (string) $file->fileObject->sha256;

        try {
            $result = $this->caption(asset: $asset, file: $file);
        } catch (AiBridgeUnavailable $error) {
            $this->log('member.describe.failed', ['asset_id' => $asset->id, 'file_id' => $fileId, 'file_object_id' => $objectId, 'error' => $error::class]);

            throw $error;
        }

        DB::transaction(function () use ($asset, $fileId, $objectId, $sha, $result): void {
            $locked = Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();
            $slots = Attachment::where('dam_asset_id', $locked->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $files = AssetFile::where('dam_asset_id', $locked->id)->where('is_current', true)->with('fileObject')->orderBy('id')->lockForUpdate()->get();
            $current = $files->firstWhere('id', $fileId);

            $same = $current !== null
                && (int) $current->file_object_id === $objectId
                && $current->fileObject?->sha256 === $sha
                && $slots->get($current->attachment_id)?->is_representative === false;

            if (! $same) {
                $this->log('member.describe.stale', ['asset_id' => $locked->id, 'file_id' => $fileId, 'file_object_id' => $objectId, 'still_current' => $current !== null]);
            }

            abort_unless($same, 409, trans('dam::app.admin.dam.asset.edit.ai.member.stale'));

            $now = now();
            DB::table('dam_ai_member_descriptions')->upsert([[
                'asset_id'       => $locked->id,
                'file_object_id' => $objectId,
                'content_sha256' => $sha,
                'caption'        => $result['caption'],
                'keywords'       => json_encode($result['keywords']),
                'visible_text'   => $result['visible_text'] === '' ? null : $result['visible_text'],
                'caption_model'  => $result['model'] === '' ? null : mb_substr($result['model'], 0, 80),
                'prompt_version' => $result['prompt_version'] === '' ? null : mb_substr($result['prompt_version'], 0, 20),
                'indexed_at'     => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]], ['asset_id', 'file_object_id'], ['content_sha256', 'caption', 'keywords', 'visible_text', 'caption_model', 'prompt_version', 'indexed_at', 'updated_at']);

            // A description of bytes that are no longer a current member of this stack (replaced, detached, promoted) is dead weight.
            DB::table('dam_ai_member_descriptions')->where('asset_id', $locked->id)
                ->whereNotIn('file_object_id', $files->pluck('file_object_id')->all())->delete();
        });

        $this->log('member.describe.saved', ['asset_id' => $asset->id, 'file_id' => $fileId, 'file_object_id' => $objectId]);

        return $this->card(asset: $asset, file: self::resolve(asset: $asset, fileId: $fileId));
    }

    /** @return array{caption: string, keywords: list<string>, visible_text: string, model: string, prompt_version: string} */
    private function caption(Asset $asset, AssetFile $file): array
    {
        $local = null;

        try {
            $extension = StackMemberNamingContext::extension(asset: $asset, file: $file);
            $local = $this->contents->localPath(object: $file->fileObject, extension: $extension ?: null);
            $sample = $this->sampler->sampleLocalFile(asset: $asset, file: $file, path: $local->path);

            if ($sample->images === []) {
                throw new AiBridgeUnavailable('This file has no readable preview yet.');
            }

            $result = $this->bridge->caption(
                images: $sample->images,
                context: ['file_name' => StackFileName::fileName(file: $file, asset: $asset), 'folder_path' => (string) ($asset->directories()->first()?->generatePath() ?? ''), 'kind' => $sample->kind],
                vocabulary: array_keys(app(AiVocabulary::class)->enabledLabels()),
            );

            if (trim($result['caption']) === '') {
                throw new AiBridgeUnavailable('The AI returned an empty description.');
            }

            return $result;
        } catch (AiBridgeUnavailable $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new AiBridgeUnavailable('This file could not be described.', previous: $error);
        } finally {
            $local?->release();
        }
    }

    /** Off unless MOTHERSHIP_AI_RENAME_LOG is set (the AI rename log's switch and file); ids and flags only, never names, captions or payloads. */
    private function log(string $event, array $ctx): void
    {
        if (! config('ai-index.rename_log')) {
            return;
        }

        $record = ['ts' => now()->toIso8601String(), 'run_id' => RequestId::current(), 'level' => 'debug',
            'event'     => $event, 'loc' => self::class, 'ctx' => $ctx];
        @file_put_contents(storage_path('logs/ai-rename.jsonl'), json_encode($record).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
