<?php

namespace Mothership\DAM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\DAM\Services\Share\ShareSlugs;

class StoreShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `web_collection_id` and `page_revision` (the page's 64-hex stack fingerprint) are the Share pages in folder
     * context (WebCollectionService): valid only on an ordinary asset link, only as a pair, and only with the plain
     * 30-day defaults, so a folder share can never carry a custom slug, password, expiry, preview-only or creator-access option.
     */
    public function rules(): array
    {
        return ShareAccessOptions::rules() + ShareSlugs::rules() + [
            'share_type'            => 'required|in:'.Share::TYPE_ASSET.','.Share::TYPE_DIRECTORY,
            'target_id'             => 'required|integer|min:1',
            'web_collection_id'     => 'nullable|integer|min:1|required_with:page_revision|prohibited_unless:share_type,'.Share::TYPE_ASSET.'|prohibits:slug,password,remove_password,allow_download,requires_creator_access,no_expiry,expires_at,name',
            'page_revision'         => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/', 'required_with:web_collection_id'],
            'expiry_days'           => $this->filled('web_collection_id') ? 'nullable|integer|in:30' : 'nullable|integer|min:1|max:365',
            'no_expiry'             => 'nullable|boolean',
            'name'                  => 'nullable|string|max:255',
            'expires_at'            => 'nullable|date|after:now',
            'public_link_confirmed' => 'accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'public_link_confirmed.accepted' => trans('dam::app.admin.dam.share.public-link-confirmation-required'),
        ];
    }
}
