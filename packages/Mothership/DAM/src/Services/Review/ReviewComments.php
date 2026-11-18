<?php

namespace Mothership\DAM\Services\Review;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Rules\ReplyParent;
use Mothership\DAM\Rules\RevisionOfAsset;
use Mothership\User\Models\Admin;

/**
 * The one writer of review comments (P7), shared by the Comments tab and the REST API so both apply the same rules.
 * Callers have already answered a hidden asset like a missing one.
 *
 * A top-level comment names a revision (by default the representative slot's current one, so a new upload or a
 * restore starts a new round by itself) and optionally an anchor. A reply inherits its parent's revision and has no
 * anchor. Mentions arrive as admin ids, never parsed from the text; each must be someone who can see the asset.
 */
class ReviewComments
{
    public const MAX_MENTIONS = 20;

    public function __construct(
        protected ReviewNotifier $notifier,
        protected AssetWatchers $watchers,
    ) {}

    /**
     * Validation for a new comment on $asset. A reply's anchor fails with its own message; its revision is ignored
     * and taken from the parent instead.
     *
     * @return array<string, mixed>
     */
    public static function rules(Asset $asset): array
    {
        return [
            'comments'      => 'required|min:2|max:1000',
            'parent_id'     => ['nullable', new ReplyParent((int) $asset->id)],
            'asset_file_id' => ['nullable', new RevisionOfAsset((int) $asset->id)],
            'anchor'        => ['nullable', 'prohibits:parent_id', CommentAnchor::forFileType($asset->file_type)],
            'mentions'      => ['nullable', 'array', 'max:'.self::MAX_MENTIONS],
            'mentions.*'    => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'comments.required' => trans('dam::app.admin.validation.comment.required'),
            'anchor.prohibits'  => trans('dam::app.admin.dam.asset.comments.review.reply-anchor'),
        ];
    }

    /**
     * Writes a validated comment and raises its notifications: mentions first, then the asset's watchers who were
     * not already told as mentions. The author is never notified of their own comment.
     *
     * @param  array<string, mixed>  $data  the validated input of rules()
     */
    public function post(Asset $asset, Admin $author, array $data): AssetComments
    {
        $parentId = $data['parent_id'] ?? null;
        $parent = $parentId === null ? null : AssetComments::query()->whereKey((int) $parentId)->first();
        $mentions = $this->mentionIds(asset: $asset, ids: (array) ($data['mentions'] ?? []));

        $comment = AssetComments::create([
            'comments'      => $data['comments'],
            'parent_id'     => $parent?->id,
            'admin_id'      => $author->id,
            'dam_asset_id'  => $asset->id,
            'asset_file_id' => $parent !== null ? $parent->asset_file_id : ($this->intOrNull($data['asset_file_id'] ?? null) ?? self::currentRevisionId($asset)),
            'anchor'        => $parent !== null ? null : CommentAnchor::normalize($data['anchor'] ?? null),
            'mentions'      => $mentions === [] ? null : $mentions,
        ]);

        $actor = ['name' => $author->name, 'asset' => $asset->display_name];
        $context = ['comment_id' => $comment->id];
        $mentioned = $this->notifier->notify(
            asset: $asset,
            adminIds: array_values(array_diff($mentions, [(int) $author->id])),
            type: ReviewNotifier::TYPE_MENTION,
            text: $this->text(key: 'notify-mention', actor: $actor),
            context: $context,
        );

        $this->notifier->notify(
            asset: $asset,
            adminIds: array_values(array_diff($this->watchers->ids($asset), [(int) $author->id], $mentioned)),
            type: ReviewNotifier::TYPE_COMMENT,
            text: $this->text(key: 'notify-comment', actor: $actor),
            context: $context,
        );

        return $comment;
    }

    /**
     * Resolves or reopens a top-level comment. Its author may, and so may anyone who may change the asset; the
     * caller says which the actor is, since the session and an API key answer that differently. Resolving tells the
     * watchers; reopening tells nobody.
     */
    public function resolve(AssetComments $comment, Admin $actor, bool $resolved, bool $actorMayEdit): AssetComments
    {
        abort_if($comment->parent_id !== null, 422, trans('dam::app.admin.dam.asset.comments.review.resolve-reply'));
        abort_unless((int) $comment->admin_id === (int) $actor->id || $actorMayEdit, 403, trans('dam::app.admin.dam.asset.comments.review.resolve-denied'));

        if ($resolved === ($comment->resolved_at !== null)) {
            return $comment;
        }

        $comment->update($resolved
            ? ['resolved_at' => now(), 'resolved_by' => $actor->id]
            : ['resolved_at' => null, 'resolved_by' => null]);

        if ($resolved) {
            $asset = $comment->asset;

            $this->notifier->notify(
                asset: $asset,
                adminIds: array_values(array_diff($this->watchers->ids($asset), [(int) $actor->id])),
                type: ReviewNotifier::TYPE_RESOLVED,
                text: $this->text(key: 'notify-resolved', actor: ['name' => $actor->name, 'asset' => $asset->display_name]),
                context: ['comment_id' => $comment->id],
            );
        }

        return $comment;
    }

    /** The representative slot's current revision: the asset's `original` file row. */
    public static function currentRevisionId(Asset $asset): ?int
    {
        $id = AssetFile::query()
            ->where('dam_asset_id', $asset->id)
            ->where('role', AssetFile::ROLE_ORIGINAL)
            ->where('is_current', true)
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The mentioned admins, as ids. An id that is not an active person fails like a missing one; someone who cannot
     * see the asset fails with their name, which the people picker already shows to anyone who can comment. Errors
     * land on $attribute.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    public function mentionIds(Asset $asset, array $ids, string $attribute = 'mentions'): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $admins = self::people()->whereIn('id', $ids)->get()->keyBy('id');

        if ($admins->count() !== count($ids)) {
            throw ValidationException::withMessages([$attribute => [trans('validation.exists', ['attribute' => $attribute])]]);
        }

        $blind = $admins->first(fn (Admin $admin) => ! $this->notifier->canSee(asset: $asset, admin: $admin));

        if ($blind !== null) {
            throw ValidationException::withMessages([$attribute => [trans('dam::app.admin.dam.asset.comments.review.mention-no-access', ['name' => $blind->name])]]);
        }

        return $ids;
    }

    /** Active people, the set the people picker offers: never a disabled admin or an integration user. */
    public static function people(): Builder
    {
        return Admin::query()
            ->where('status', true)
            ->whereNotIn('id', DB::table('admins')->where('type', 'api')->select('id'));
    }

    /**
     * @param  array{name: string, asset: string}  $actor
     * @return array{title: string, description: string}
     */
    protected function text(string $key, array $actor): array
    {
        return [
            'title'       => trans('dam::app.admin.dam.asset.comments.review.'.$key, ['name' => $actor['name']]),
            'description' => trans('dam::app.admin.dam.asset.comments.review.notify-on', ['asset' => $actor['asset']]),
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
