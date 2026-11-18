<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\AiIndex\AiVocabulary;
use Mothership\DAM\Http\Controllers\AiVocabularyController as LibraryAiVocabularyController;

/** P5c (D8) REST twins of the AI vocabulary screen: list, add and change terms, through AiVocabulary::save(). */
class AiVocabularyController extends Controller
{
    public function __construct(private AiVocabulary $vocabulary) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['terms' => LibraryAiVocabularyController::terms($this->vocabulary), 'max_enabled' => AiVocabulary::MAX_ENABLED]]);
    }

    public function store(Request $request): JsonResponse
    {
        $term = $this->vocabulary->save(term: null, data: LibraryAiVocabularyController::input($request), actorId: auth('api')->id());

        return response()->json(['success' => true, 'data' => $term], 201);
    }

    public function update(Request $request, string $term): JsonResponse
    {
        $saved = $this->vocabulary->save(term: $term, data: LibraryAiVocabularyController::input($request), actorId: auth('api')->id());

        return response()->json(['success' => true, 'data' => $saved]);
    }
}
