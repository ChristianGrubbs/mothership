<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Duplicates\StackSuggestions;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\User\Models\Admin;

/**
 * P3b-2 (D5 suggestions): the Library's "Suggested stacks" panel. Lists groups of entries the admin can see that look
 * like one stack, dismisses one for this admin only, and "Stack them" gives the group's entries one basis and key
 * through the P3a stack flow. Nothing happens without one of those two clicks.
 */
class StackSuggestionController
{
    public function __construct(private StackSuggestions $suggestions) {}

    public function index(): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));

        return response()->json(['suggestions' => $this->suggestions->for(viewer: $this->viewer(), context: AccessContext::library(AccessContext::PURPOSE_LIST))]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $data = self::validateGroup($request);

        if ($data['kind'] === StackSuggestions::KIND_NEAR_GROUP) {
            // Only the group's own pairs, and only while it is the set the viewer reviewed.
            $result = app(SimilarImages::class)->dismissGroups(
                viewer: $this->viewer(),
                context: AccessContext::library(AccessContext::PURPOSE_LIST),
                groups: [['key' => $data['group_key'], 'members' => array_map('intval', $data['members'])]],
            );
            if ($result['dismissed'] === 0) {
                SimilarImages::changed();
            }

            return response()->json(['dismissed' => true]);
        }

        $this->suggestions->dismiss(viewer: $this->viewer(), kind: $data['kind'], groupKey: $data['group_key']);

        return response()->json(['dismissed' => true]);
    }

    public function apply(Request $request): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.edit'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $group = self::validateGroup($request);
        $stack = self::validateStack($request);

        $result = $group['kind'] === StackSuggestions::KIND_NEAR_GROUP
            ? $this->suggestions->applyGroup(
                viewer: $this->viewer(),
                context: AccessContext::library(AccessContext::PURPOSE_LIST),
                members: array_map('intval', $group['members']),
                groupKey: $group['group_key'],
                basis: $stack['basis'],
                key: $stack['key'],
            )
            : $this->suggestions->apply(
                viewer: $this->viewer(),
                context: AccessContext::library(AccessContext::PURPOSE_LIST),
                kind: $group['kind'],
                groupKey: $group['group_key'],
                basis: $stack['basis'],
                key: $stack['key'],
            );

        return response()->json([...$result, 'message' => trans_choice('dam::app.admin.dam.suggestions.stacked', $result['stacked'], ['count' => $result['stacked']])]);
    }

    /**
     * A `near_group` names the members it was reviewed with (2 to MAX_MEMBERS asset ids); the service refuses the request
     * with HTTP 409 when that set is no longer what the viewer saw. The other kinds are looked up by their key as before.
     *
     * @return array{kind: string, group_key: string, members: list<int|string>}
     */
    public static function validateGroup(Request $request): array
    {
        $data = $request->validate([
            'kind'        => ['required', 'string', Rule::in(StackSuggestions::KINDS)],
            'group_key'   => ['required', 'string', 'max:191'],
            'members'     => [Rule::requiredIf(fn () => $request->input('kind') === StackSuggestions::KIND_NEAR_GROUP), 'array', 'min:2', 'max:'.StackSuggestions::MAX_MEMBERS],
            'members.*'   => ['integer', 'min:1'],
        ]);
        $data['members'] ??= [];

        return $data;
    }

    /**
     * A stack needs both: the basis cannot be cleared here, and the key is trimmed and not blank.
     *
     * @return array{basis: string, key: string}
     */
    public static function validateStack(Request $request): array
    {
        $required = trans('dam::app.admin.dam.asset.stack.key-required');
        $data = $request->validate([
            'basis' => ['required', 'string', Rule::in(Asset::STACK_BASES)],
            'key'   => ['required', 'string', 'max:191'],
        ], ['key.required' => $required]);

        if (trim($data['key']) === '') {
            throw ValidationException::withMessages(['key' => [$required]]);
        }

        return $data;
    }

    private function viewer(): Admin
    {
        $viewer = auth('admin')->user();
        abort_unless($viewer instanceof Admin, 403);

        return $viewer;
    }
}
