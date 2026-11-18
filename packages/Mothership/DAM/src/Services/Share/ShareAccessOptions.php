<?php

namespace Mothership\DAM\Services\Share;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Mothership\DAM\Models\Share;

/**
 * P2 S5b: a link's access options as the web dialog and REST both take them, in one place so the two never drift.
 * - `password` sets or changes the password (stored only as its hash); `remove_password` clears it.
 * - `allow_download` false makes the link preview-only.
 * - `requires_creator_access` true makes the link stop working once its creator can no longer see the target.
 *
 * Every field is optional: on create an absent one takes its default (no password, downloads on, creator access
 * off); on update an absent one leaves the link as it is. Who may send them is the caller's existing gate (create,
 * or edit the link), so clearing a password needs exactly the authority editing one does.
 */
final class ShareAccessOptions
{
    public const PASSWORD_MIN = 4;

    public const PASSWORD_MAX = 128;

    /** @return array<string, string> */
    public static function rules(): array
    {
        return [
            'password'                => 'sometimes|nullable|string|min:'.self::PASSWORD_MIN.'|max:'.self::PASSWORD_MAX.'|prohibited_if_accepted:remove_password',
            'remove_password'         => 'sometimes|boolean',
            'allow_download'          => 'sometimes|boolean',
            'requires_creator_access' => 'sometimes|boolean',
        ];
    }

    /**
     * The `dam_shares` columns the request asks to change. An empty or absent password changes nothing, so a dialog
     * that re-sends its other fields never clears one by accident; only `remove_password` does.
     *
     * @return array{password_hash?: ?string, allow_download?: bool, requires_creator_access?: bool}
     */
    public static function columns(Request $request): array
    {
        $columns = [];

        if ($request->boolean('remove_password')) {
            $columns['password_hash'] = null;
        } elseif (is_string($password = $request->input('password')) && $password !== '') {
            $columns['password_hash'] = Hash::make($password);
        }

        foreach (['allow_download', 'requires_creator_access'] as $flag) {
            if ($request->has($flag)) {
                $columns[$flag] = $request->boolean($flag);
            }
        }

        return $columns;
    }

    /**
     * What every API shows of a link's options: never the password or its hash, only whether one is set.
     *
     * @return array{has_password: bool, allow_download: bool, requires_creator_access: bool}
     */
    public static function present(Share $share): array
    {
        return [
            'has_password'            => $share->hasPassword(),
            'allow_download'          => $share->allowsDownload(),
            'requires_creator_access' => (bool) $share->requires_creator_access,
        ];
    }
}
