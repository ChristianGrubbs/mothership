<?php

namespace Mothership\AdminApi\Http\Requests\Catalog;

use Illuminate\Validation\Rule;
use Mothership\AdminApi\Http\Requests\ApiFormRequest;
use Mothership\Category\Rules\ValidationTypes;
use Mothership\Core\Rules\Code;
use Mothership\Product\Repositories\AssociationTypeRepository;
use Mothership\Product\Rules\AssociationFieldTypes;
use Mothership\Product\Rules\AssociationNotSupportedFields;

class StoreAssociationTypeFieldRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $associationType = app(AssociationTypeRepository::class)->findByCode((string) $this->route('code'));

        $rules = [
            'code' => [
                'required',
                new Code,
                new AssociationNotSupportedFields,
                Rule::unique('association_type_fields', 'code')->where(
                    fn ($query) => $query->where('association_type_id', $associationType?->id)
                ),
            ],
            'type'             => ['required', new AssociationFieldTypes],
            'status'           => ['sometimes', 'boolean'],
            'validation'       => ['sometimes', 'nullable', new ValidationTypes],
            'is_required'      => ['sometimes', 'boolean'],
            'is_unique'        => ['sometimes', 'boolean'],
            'value_per_locale' => ['sometimes', 'boolean'],
        ];

        foreach (core()->getAllActiveLocales() as $locale) {
            $rules[$locale->code.'.name'] = ['sometimes', 'nullable', 'string'];
        }

        return $rules;
    }
}
