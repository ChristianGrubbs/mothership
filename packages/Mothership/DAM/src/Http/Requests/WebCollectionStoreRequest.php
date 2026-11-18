<?php

namespace Mothership\DAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Mothership\DAM\Services\WebCollectionService;

/** Shape only (the length is the service's one limit); name rules (length, slash, control characters, uniqueness) live in WebCollectionService. */
class WebCollectionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:'.WebCollectionService::NAME_MAX,
            'parent_id' => 'nullable|integer|min:1',
        ];
    }
}
