<?php

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\PageGrant;

it('round-trips a grant and binds it to the asset, version and expiry', function () {
    $grant = PageGrant::issue(7, 2, 3);

    expect(PageGrant::verify($grant, 7))->toMatchArray(['version' => 2, 'admin' => 3])
        ->and(PageGrant::verify($grant, 8))->toBeNull()
        ->and(PageGrant::verify($grant.'x', 7))->toBeNull()
        ->and(PageGrant::verify(PageGrant::issue(7, 2, 3, ttlSeconds: -1), 7))->toBeNull();
});

it('binds a member grant to asset, slot, file, full checksum and admin, apart from the legacy grant', function () {
    $asset = new Asset;
    $asset->id = 7;
    $file = new AssetFile;
    $file->id = 21;
    $file->attachment_id = 5;
    $sum = hash('sha256', 'page');
    $grant = PageGrant::issueMember($asset, $file, $sum, 3);

    expect(PageGrant::verifyMember($grant, 7))->toMatchArray(['asset' => 7, 'attachment' => 5, 'file' => 21, 'checksum' => $sum, 'admin' => 3])
        ->and(PageGrant::verifyMember($grant, 8))->toBeNull()
        ->and(PageGrant::verifyMember($grant.'x', 7))->toBeNull()
        ->and(PageGrant::verifyMember(PageGrant::issueMember($asset, $file, $sum, 3, ttlSeconds: -1), 7))->toBeNull()
        // The two kinds never open each other.
        ->and(PageGrant::verify($grant, 7))->toBeNull()
        ->and(PageGrant::verifyMember(PageGrant::issue(7, 2, 3), 7))->toBeNull();

    // A claim edited to name another file or checksum no longer verifies, whatever else it keeps.
    $raw = base64_decode(strtr($grant, '-_', '+/').str_repeat('=', (4 - strlen($grant) % 4) % 4));
    [$payload, $signature] = [substr($raw, 0, strrpos($raw, '.')), substr($raw, strrpos($raw, '.') + 1)];
    $forge = fn (string $changed) => rtrim(strtr(base64_encode($changed.'.'.$signature), '+/', '-_'), '=');
    expect(PageGrant::verifyMember($forge(str_replace('.21.', '.22.', $payload)), 7))->toBeNull()
        ->and(PageGrant::verifyMember($forge(str_replace($sum, str_repeat('b', 64), $payload)), 7))->toBeNull();
});
