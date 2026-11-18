<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;

class StoreCategoryMediaRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file'           => 'required',
            'code'           => 'required',
            'category_field' => 'required',
        ];
    }
}
