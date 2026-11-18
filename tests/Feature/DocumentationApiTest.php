<?php

it('publishes the reference and allowlisted downloads for administrators with configuration', function () {
    $this->loginWithPermissions(permissions: ['configuration']);
    $this->get('/admin/help/api')->assertOk()->assertSeeText('API reference');
    $download = $this->get('/admin/help/api/openapi.json')->assertOk()->assertDownload('openapi.json');
    expect(json_decode(file_get_contents($download->baseResponse->getFile()->getPathname()), true)['openapi'])->toBe('3.1.0');
    $this->get('/admin/help/api/routes.json')->assertOk();
    $this->get('/admin/help/api/components.json')->assertNotFound();
});

it('denies reference and downloads without configuration permission, help alone included', function (array $permissions) {
    $this->loginWithPermissions(permissions: $permissions);
    foreach (['/admin/help/api', '/admin/help/api/openapi.json', '/admin/help/api/routes.json'] as $url) {
        $this->get($url)->assertForbidden();
    }
})->with([
    'unrelated permission' => [['dashboard']],
    'help only'            => [['help']],
]);

it('redirects guests away from reference and downloads', function () {
    foreach (['/admin/help/api', '/admin/help/api/openapi.json'] as $url) {
        $this->get($url)->assertRedirect();
    }
});
