<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Models\Asset;

/** Sets or clears why an asset's files are stacked (basis) and what binds them (key). */
final class AssetStackService
{
    /**
     * The request rules the admin UI and REST share: a basis from Asset::STACK_BASES (or null to clear) and,
     * with a basis, a key that is not blank once trimmed.
     *
     * @return array{basis: ?string, key?: ?string}
     */
    public static function validate(Request $request): array
    {
        $required = trans('dam::app.admin.dam.asset.stack.key-required');
        $data = $request->validate([
            'basis' => ['present', 'nullable', 'string', Rule::in(Asset::STACK_BASES)],
            'key'   => ['nullable', 'string', 'max:191', 'required_with:basis'],
        ], ['key.required_with' => $required]);

        if ($data['basis'] !== null && trim((string) ($data['key'] ?? '')) === '') {
            throw ValidationException::withMessages(['key' => [$required]]);
        }

        return $data;
    }

    /** @return array{basis: ?string, key: ?string} */
    public function set(Asset $asset, ?string $basis, ?string $key): array
    {
        $key = $basis === null ? null : trim((string) $key);

        // Any change of the stack, setting one on an empty file included, is an edit of the file: own-only. Asked of the
        // row as stored under a lock, so a stack someone filled after the caller loaded the asset is judged as it is now.
        $changed = DB::transaction(function () use ($asset, $basis, $key): bool {
            $stored = Asset::query()->whereKey($asset->id)->lockForUpdate()->first() ?? $asset;

            if ($stored->stack_basis === $basis && $stored->stack_key === $key) {
                $asset->forceFill(['stack_basis' => $basis, 'stack_key' => $key])->syncOriginalAttributes(['stack_basis', 'stack_key']);

                return false;
            }

            app(RoleBuilder::class)->abortUnlessOwn(model: $stored);

            // A basis without a key would be a stack nobody can filter or search for, so the request rules require both.
            $stored->forceFill(['stack_basis' => $basis, 'stack_key' => $key])->save();
            $asset->forceFill(['stack_basis' => $basis, 'stack_key' => $key])->syncOriginalAttributes(['stack_basis', 'stack_key']);

            return true;
        });

        if ($changed) {
            AiIndexDispatcher::lexical([$asset->id]);
        }

        return self::payload($asset);
    }

    /** @return array{basis: ?string, key: ?string} */
    public static function payload(Asset $asset): array
    {
        return ['basis' => $asset->stack_basis, 'key' => $asset->stack_key];
    }

    public static function label(?string $basis): string
    {
        return $basis === null ? '' : (string) trans('dam::app.admin.dam.asset.stack.bases.'.$basis);
    }
}
