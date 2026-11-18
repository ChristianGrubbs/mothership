<?php

namespace Mothership\DAM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Share;

class ShareFactory extends Factory
{
    protected $model = Share::class;

    public function definition(): array
    {
        // Stored as a new link is (P2 S5a): hashed and encrypted, no plaintext. Tests read it with urlToken().
        return Share::tokenColumns(Str::random(40)) + [
            'share_type' => Share::TYPE_ASSET,
            'target_id'  => 0,
            'created_by' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    /** A link stored before P2 S5a: plaintext `token`, no hash or encrypted copy. */
    public function legacy(): self
    {
        return $this->state(fn () => [
            'token'           => Str::random(40),
            'token_hash'      => null,
            'token_encrypted' => null,
        ]);
    }

    public function forAsset(int $assetId): self
    {
        return $this->state(fn () => [
            'share_type' => Share::TYPE_ASSET,
            'target_id'  => $assetId,
        ]);
    }

    public function forDirectory(int $directoryId): self
    {
        return $this->state(fn () => [
            'share_type' => Share::TYPE_DIRECTORY,
            'target_id'  => $directoryId,
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): self
    {
        return $this->state(fn () => [
            'revoked_at' => now()->subMinute(),
        ]);
    }

    public function noExpiry(): self
    {
        return $this->state(fn () => [
            'expires_at' => null,
        ]);
    }
}
