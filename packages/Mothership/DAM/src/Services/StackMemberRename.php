<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\AiIndex\StackMemberNamingContext;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Support\RequestId;
use Mothership\DAM\Support\StackFileName;
use Symfony\Component\Mime\MimeTypes;

class StackMemberRename
{
    public function rename(Asset $asset, int $attachmentId, int $expectedFileId, string $fileName, ?string $title = null): array
    {
        Validator::make(['file_name' => $fileName, 'title' => $title], [
            'file_name' => AssetRename::FILE_NAME_RULE, 'title' => 'nullable|string|max:160',
        ])->validate();

        return DB::transaction(function () use ($asset, $attachmentId, $expectedFileId, $fileName, $title): array {
            $locked = Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();
            app(RoleBuilder::class)->abortUnlessOwn(model: $locked, reason: 'own-only-label');
            $slots = Attachment::where('dam_asset_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $slot = $slots->firstWhere('id', $attachmentId);
            abort_unless($slot, 404);
            $files = AssetFile::where('dam_asset_id', $locked->id)->where('is_current', true)
                ->with('fileObject')->orderBy('id')->lockForUpdate()->get()->keyBy('attachment_id');
            $file = $files->get($attachmentId);
            abort_unless($file && (int) $file->id === $expectedFileId, 409, 'The current file has changed. Refresh this row.');
            $file->setRelation('attachment', $slot);
            $oldName = StackFileName::fileName(file: $file, asset: $locked);
            $suffix = pathinfo($oldName, PATHINFO_EXTENSION);
            $resolvedExtension = StackMemberNamingContext::extension(asset: $locked, file: $file);
            // A dotted description is not a type. Preserve a spelling such as JPEG only when the member's
            // resolver or registered MIME aliases verify it; a representative keeps AssetRename's contract.
            $matchesType = strcasecmp($suffix, $resolvedExtension) === 0
                || in_array(strtolower($suffix), MimeTypes::getDefault()->getExtensions((string) $file->fileObject?->mime_type), true);
            $extension = $slot->is_representative || $matchesType ? $suffix : $resolvedExtension;
            $name = trim($fileName) === $oldName ? $oldName : pathinfo(trim($fileName), PATHINFO_FILENAME).($extension === '' ? '' : '.'.$extension);
            abort_if(mb_strlen($name) > 191, 422, 'The file name is too long.');
            foreach ($slots as $other) {
                if ($other->id === $slot->id || ! ($sibling = $files->get($other->id))) {
                    continue;
                }
                $sibling->setRelation('attachment', $other);
                abort_if(mb_strtolower(StackFileName::fileName(file: $sibling, asset: $locked)) === mb_strtolower($name), 422, 'Another file in this stack has that name.');
            }
            $changed = $oldName !== $name;
            if ($slot->is_representative) {
                $result = app(AssetRename::class)->rename(asset: $locked, fileName: $name, title: $title);
                $name = (string) $locked->file_name;
                $changed = $result['file_changed'] || $result['title_changed'];
            }
            if ($changed) {
                app(AttachmentLedger::class)->update(asset: $locked, attachmentId: $attachmentId, data: ['label' => $name]);
                AiIndexDispatcher::lexical([(int) $locked->id]);
            }
            $status = $changed ? 'renamed' : 'unchanged';
            if (config('ai-index.rename_log')) {
                @file_put_contents(storage_path('logs/ai-rename.jsonl'), json_encode([
                    'ts'    => now()->toIso8601String(), 'run_id' => RequestId::current(), 'level' => 'debug',
                    'event' => 'rename.member_applied', 'loc' => self::class,
                    'ctx'   => ['asset_id' => (int) $locked->id, 'attachment_id' => $attachmentId, 'file_id' => $expectedFileId, 'status' => $status],
                ]).PHP_EOL, FILE_APPEND | LOCK_EX);
            }

            return ['status' => $status, 'file_name' => $name, 'file_id' => $expectedFileId];
        });
    }
}
