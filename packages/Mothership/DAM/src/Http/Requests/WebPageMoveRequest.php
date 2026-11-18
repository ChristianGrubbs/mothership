<?php

namespace Mothership\DAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Files pages into a collection (`collection_id`, null = unfiled). */
class WebPageMoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_ids'     => 'required|array|min:1|max:500',
            'asset_ids.*'   => 'integer|min:1',
            'collection_id' => 'present|nullable|integer|min:1',
        ];
    }
}
