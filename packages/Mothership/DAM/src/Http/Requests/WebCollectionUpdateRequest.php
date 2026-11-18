<?php

namespace Mothership\DAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Mothership\DAM\Services\WebCollectionService;

/** Rename (`name`) and/or move (`parent_id`, null = top level); at least one is required. */
class WebCollectionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => 'sometimes|required|string|max:'.WebCollectionService::NAME_MAX,
            'parent_id' => 'nullable|integer|min:1',
        ];
    }

    /** A null parent_id is a real move (to the top level), so presence, not a value, is what counts. */
    public function after(): array
    {
        return [function ($validator) {
            if (! $this->exists('name') && ! $this->exists('parent_id')) {
                $validator->errors()->add('name', trans('validation.required', ['attribute' => 'name']));
            }
        }];
    }
}
