<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Attribute\Rules\AttributeTypes;
use Mothership\Attribute\Rules\NotSupportedAttributes;
use Mothership\Attribute\Rules\SwatchTypes;
use Mothership\Attribute\Rules\ValidationTypes;
use Mothership\Core\Rules\Code;

class StoreAttributeRequest extends ApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'type' => [
                'required',
                new AttributeTypes,
            ],
            'code' => [
                'required',
                sprintf('unique:%s,code', 'attributes'),
                new Code,
                new NotSupportedAttributes,
            ],
            'swatch_type' => [
                'nullable',
                new SwatchTypes,
            ],
        ];

        if ($this->input('validation')) {
            $rules['validation'] = [new ValidationTypes];
        }

        return $rules;
    }
}
