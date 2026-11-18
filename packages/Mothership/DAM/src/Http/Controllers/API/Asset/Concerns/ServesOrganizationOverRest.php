<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Organization\LibraryListing;

/**
 * P4 (REST parity): the Library organization endpoints over REST. The API key's permission for the route (api.scope)
 * is the gate in place of the web ACL, and every read goes through the API listing context for the key's admin.
 */
trait ServesOrganizationOverRest
{
    protected function context(): AccessContext
    {
        return AccessContext::api(AccessContext::PURPOSE_LIST);
    }

    protected function authorizeLibrary(): void
    {
        // api.scope has already checked the key's permission for this route.
    }

    /**
     * A page of a Library listing: the request's own filters plus the container the route names.
     *
     * @param  array<string, mixed>  $container  e.g. ['label_id' => 3]
     */
    protected function listing(Request $request, array $container = []): JsonResponse
    {
        $params = LibraryListing::foldPropertyFilters(LibraryListing::restorePropertyKeys(params: $request->query(), rawQuery: (string) $request->server->get('QUERY_STRING', '')));
        $rules = LibraryListing::rules();
        unset($rules['label_id'], $rules['section_id']);
        $validated = validator($params, $rules + [
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.LibraryListing::MAX_PER_PAGE,
        ])->validate();

        return $this->run(params: array_diff_key($validated, ['page' => 1, 'per_page' => 1]) + $container, request: $request);
    }

    /** @param  array<string, mixed>  $params */
    protected function run(array $params, Request $request, bool $stored = false): JsonResponse
    {
        $result = app(LibraryListing::class)->run(
            params: $params,
            context: $this->context(),
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 50),
            stored: $stored,
        );

        return new JsonResponse(['success' => true, 'data' => $result['assets'], 'meta' => $result['meta']]);
    }
}
