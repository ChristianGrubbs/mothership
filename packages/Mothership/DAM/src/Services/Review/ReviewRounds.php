<?php

namespace Mothership\DAM\Services\Review;

use Illuminate\Support\Collection;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\ReviewRequest;
use Mothership\User\Models\Admin;

/**
 * What the Comments tab and the REST review read show (P7): the asset's top-level comments grouped into rounds, its
 * open feedback requests and whether the viewer watches it.
 *
 * A round is derived, never stored: the top-level comments on one revision. Rounds come newest revision first;
 * comments that name no revision (written before P7, or whose revision was removed) form a last group of their own.
 * A comment on a revision that is no longer current is marked `is_previous_revision`. Each revision says what plays
 * it on video and audio (`playback`), so a refreshed read never leaves the player on a stale source.
 */
class ReviewRounds
{
    public function __construct(protected AssetWatchers $watchers) {}

    /** @return array<string, mixed> */
    public function payload(Asset $asset, Admin $viewer, bool $viewerMayEdit): array
    {
        $comments = AssetComments::query()
            ->where('dam_asset_id', $asset->id)
            ->whereNull('parent_id')
            ->with(['admin', 'resolver', 'children' => fn ($children) => $children->with('admin')->orderBy('created_at')->orderBy('id')])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $requests = ReviewRequest::query()
            ->where('dam_asset_id', $asset->id)
            ->whereNull('closed_at')
            ->with(['requester', 'recipients' => fn ($recipients) => $recipients->orderBy('name')])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $revisions = AssetFile::query()
            ->where('dam_asset_id', $asset->id)
            ->whereIn('id', $comments->pluck('asset_file_id')->merge($requests->pluck('asset_file_id'))->filter()->unique()->values())
            ->with('attachment')
            ->get()
            ->keyBy('id');

        $currentId = ReviewComments::currentRevisionId($asset);
        $current = $currentId === null ? null : AssetFile::query()->with('attachment')->find($currentId);
        $names = $this->mentionNames($comments);

        return [
            'current_revision' => $current === null ? null : $this->revision(file: $current, asset: $asset),
            'rounds'           => $this->rounds(comments: $comments, revisions: $revisions, names: $names, asset: $asset),
            'requests'         => $requests->map(fn (ReviewRequest $request) => [
                'id'              => $request->id,
                'requested_by'    => $this->person($request->requester),
                'message'         => $request->message,
                'due_at'          => $request->due_at?->toIso8601String(),
                'created_at'      => $request->created_at?->toIso8601String(),
                'asset_file_id'   => $request->asset_file_id,
                'revision_number' => $revisions->get($request->asset_file_id)?->revision_number,
                'recipients'      => $request->recipients->map(fn (Admin $admin) => $this->person($admin))->values()->all(),
                'can_close'       => $viewerMayEdit || (int) $request->requested_by === (int) $viewer->id,
            ])->values()->all(),
            'watching'         => $this->watchers->isWatching($asset, (int) $viewer->id),
            'can_moderate'     => $viewerMayEdit,
        ];
    }

    /**
     * One comment as the tab shows it, replies included.
     *
     * @param  Collection<int, AssetFile>  $revisions
     * @param  array<int, string>  $names  mentioned admin ids => names
     * @return array<string, mixed>
     */
    public function comment(AssetComments $comment, Collection $revisions, array $names): array
    {
        $revision = $comment->asset_file_id === null ? null : $revisions->get($comment->asset_file_id);

        return [
            'id'                   => $comment->id,
            'admin_id'             => $comment->admin_id,
            'admin'                => $this->person($comment->admin),
            'comments'             => $comment->comments,
            'parent_id'            => $comment->parent_id,
            'dam_asset_id'         => $comment->dam_asset_id,
            'asset_file_id'        => $comment->asset_file_id,
            'anchor'               => $comment->anchor,
            'mentions'             => collect($comment->mentions ?? [])
                ->map(fn ($id) => ['id' => (int) $id, 'name' => $names[(int) $id] ?? trans('dam::app.admin.dam.asset.comments.deleted-user')])
                ->values()->all(),
            'resolved_at'          => $comment->resolved_at?->toIso8601String(),
            'resolved_by'          => $comment->resolved_by,
            'resolver'             => $comment->resolved_by === null ? null : $this->person($comment->resolver),
            'is_previous_revision' => $revision !== null && ! $revision->is_current,
            'created_at'           => $comment->created_at?->toIso8601String(),
            'updated_at'           => $comment->updated_at?->toIso8601String(),
            'children'             => $comment->relationLoaded('children')
                ? $comment->children->map(fn (AssetComments $reply) => $this->comment(comment: $reply, revisions: $revisions, names: $names))->values()->all()
                : [],
        ];
    }

    /**
     * @param  Collection<int, AssetComments>  $comments
     * @param  Collection<int, AssetFile>  $revisions
     * @param  array<int, string>  $names
     * @return list<array<string, mixed>>
     */
    protected function rounds(Collection $comments, Collection $revisions, array $names, Asset $asset): array
    {
        return $comments
            ->groupBy(fn (AssetComments $comment) => $comment->asset_file_id !== null && $revisions->has($comment->asset_file_id) ? (int) $comment->asset_file_id : 0)
            ->map(function (Collection $group, int $fileId) use ($revisions, $names, $asset) {
                $file = $revisions->get($fileId);

                return [
                    'key'      => $file === null ? 'earlier' : 'revision-'.$fileId,
                    'revision' => $file === null ? null : $this->revision(file: $file, asset: $asset),
                    'comments' => $group->map(fn (AssetComments $comment) => $this->comment(comment: $comment, revisions: $revisions, names: $names))->values()->all(),
                ];
            })
            // Newest revision first, by when it was made; the group with no revision last.
            ->sortByDesc(fn (array $round) => $round['revision'] === null ? '' : $round['revision']['created_at'].'#'.str_pad((string) $round['revision']['id'], 12, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    /**
     * One revision as the tab shows it. On video and audio, `playback` names the file that plays it (its own proxy,
     * or itself), streamed through the asset-file route; null means nothing a browser can play exists for it.
     *
     * @return array<string, mixed>
     */
    protected function revision(AssetFile $file, Asset $asset): array
    {
        return [
            'id'                => $file->id,
            'revision_number'   => (int) $file->revision_number,
            'is_current'        => (bool) $file->is_current,
            'is_representative' => (bool) $file->attachment?->is_representative,
            'label'             => $file->attachment?->is_representative ? null : ($file->attachment?->label ?: $file->label),
            'created_at'        => $file->created_at?->toIso8601String() ?? '',
            'playback'          => $asset->revisionPlayback($file),
        ];
    }

    /** @return array{id: int, name: string, image_url: ?string}|null */
    protected function person(?Admin $admin): ?array
    {
        return $admin === null ? null : ['id' => (int) $admin->id, 'name' => (string) $admin->name, 'image_url' => $admin->image_url];
    }

    /**
     * @param  Collection<int, AssetComments>  $comments
     * @return array<int, string>
     */
    protected function mentionNames(Collection $comments): array
    {
        $ids = $comments->flatMap(fn (AssetComments $comment) => [...($comment->mentions ?? []), ...$comment->children->flatMap(fn (AssetComments $reply) => $reply->mentions ?? [])])
            ->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? [] : Admin::query()->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])->all();
    }
}
