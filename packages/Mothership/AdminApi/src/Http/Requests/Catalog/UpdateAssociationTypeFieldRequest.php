<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Category\Rules\ValidationTypes;

class UpdateAssociationTypeFieldRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $rules = [
            'validation'       => ['sometimes', 'nullable', new ValidationTypes],
            'is_required'      => ['sometimes', 'boolean'],
            'is_unique'        => ['sometimes', 'boolean'],
            'value_per_locale' => ['sometimes', 'boolean'],
            'status'           => ['sometimes', 'boolean'],
        ];

        foreach (core()->getAllActiveLocales() as $locale) {
            $rules[$locale->code.'.name'] = ['sometimes', 'nullable', 'string'];
        }

        return $rules;
    }
}
