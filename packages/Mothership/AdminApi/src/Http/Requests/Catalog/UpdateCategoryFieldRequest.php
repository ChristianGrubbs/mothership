<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Category\Repositories\CategoryFieldRepository;
use Mothership\Category\Rules\CategoryFieldValidationRules;

class UpdateCategoryFieldRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('validation')) {
            $this->merge(['validation' => CategoryFieldValidationRules::normalize($this->input('validation'))]);
        }
    }

    public function rules(): array
    {
        $categoryField = app(CategoryFieldRepository::class)->findOneByField('code', $this->route('code'));

        if (! $categoryField) {
            return [];
        }

        $validation = $this->has('validation')
            ? $this->input('validation')
            : $categoryField->validation;

        $rules = CategoryFieldValidationRules::for($categoryField->type, $validation);

        if (! $this->has('validation') && ! $this->has('regex_pattern')) {
            unset($rules['regex_pattern']);
        }

        return $rules;
    }
}
