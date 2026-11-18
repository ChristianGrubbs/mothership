<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Core\Rules\Code;

class StoreCategoryRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code'            => ['required', 'string', 'unique:categories,code', new Code],
            'additional_data' => ['sometimes', 'array'],
            'parent'          => ['sometimes', 'nullable', 'string'],
        ];
    }
}
