<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Attribute\Rules\ValidationTypes;

class UpdateAttributeRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'is_required'    => ['sometimes', 'boolean'],
            'enable_wysiwyg' => ['sometimes', 'boolean'],
        ];

        if (! empty($this->input('validation'))) {
            $rules['validation'] = [new ValidationTypes];
        }

        return $rules;
    }
}
