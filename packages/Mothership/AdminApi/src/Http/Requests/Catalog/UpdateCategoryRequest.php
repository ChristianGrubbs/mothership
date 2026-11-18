<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;

class UpdateCategoryRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'additional_data' => ['sometimes', 'array'],
            'parent'          => ['sometimes', 'nullable', 'string'],
        ];
    }
}
