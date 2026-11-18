<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mothership\DAM\AiIndex\AiVocabulary;

/**
 * P5c (D8): Settings, Library, AI vocabulary. Adds terms, renames their labels, disables them, edits synonyms and
 * maps a term to an existing tag, under dam.ai.vocabulary.manage. Every write goes through AiVocabulary::save(), as
 * REST does: TagNameRule on labels and synonyms, the bridge's 200-term cap, and the lexical re-index of a rename.
 */
class AiVocabularyController
{
    public function __construct(private AiVocabulary $vocabulary) {}

    public function index(Request $request): View|JsonResponse
    {
        $this->authorize();
        $terms = self::terms($this->vocabulary);

        if ($request->expectsJson()) {
            return response()->json(['terms' => $terms, 'max_enabled' => AiVocabulary::MAX_ENABLED]);
        }

        return view('dam::configuration.ai-vocabulary', ['initial' => [
            'terms'        => $terms,
            'max_enabled'  => AiVocabulary::MAX_ENABLED,
            'max_synonyms' => AiVocabulary::MAX_SYNONYMS,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize();
        $term = $this->vocabulary->save(term: null, data: self::input($request), actorId: auth('admin')->id());

        return response()->json(['term' => $term, 'message' => trans('dam::app.admin.ai-vocabulary.saved')], 201);
    }

    public function update(Request $request, string $term): JsonResponse
    {
        $this->authorize();
        $saved = $this->vocabulary->save(term: $term, data: self::input($request), actorId: auth('admin')->id());

        return response()->json(['term' => $saved, 'message' => trans('dam::app.admin.ai-vocabulary.saved')]);
    }

    /** @return list<array<string, mixed>> */
    public static function terms(AiVocabulary $vocabulary): array
    {
        return array_values(array_map(fn (string $term) => $vocabulary->present($term), array_keys($vocabulary->all())));
    }

    /** @return array<string, mixed> only the fields the request names, so an update changes nothing else */
    public static function input(Request $request): array
    {
        $request->validate([
            'term'           => ['sometimes', 'string', 'max:64'],
            'label'          => ['sometimes', 'string', 'max:191'],
            'enabled'        => ['sometimes', 'boolean'],
            'synonyms'       => ['sometimes', 'nullable'],
            'synonyms.*'     => ['string', 'max:191'],
            'maps_to_tag_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return $request->only(['term', 'label', 'enabled', 'synonyms', 'maps_to_tag_id']);
    }

    private function authorize(): void
    {
        abort_unless(bouncer()->hasPermission('dam.ai.vocabulary.manage'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }
}
