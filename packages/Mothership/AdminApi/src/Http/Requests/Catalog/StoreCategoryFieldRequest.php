<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Category\Rules\CategoryFieldValidationRules;
use Mothership\Category\Rules\FieldTypes;
use Mothership\Category\Rules\NotSupportedFields;
use Mothership\Core\Rules\Code;

class StoreCategoryFieldRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('validation')) {
            $this->merge(['validation' => CategoryFieldValidationRules::normalize($this->input('validation'))]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'code' => [
                'required',
                sprintf('unique:%s,code', 'category_fields'),
                new Code,
                new NotSupportedFields,
            ],
            'type' => [
                'required',
                new FieldTypes,
            ],
        ];

        return $rules + CategoryFieldValidationRules::for($this->input('type'), $this->input('validation'));
    }
}
