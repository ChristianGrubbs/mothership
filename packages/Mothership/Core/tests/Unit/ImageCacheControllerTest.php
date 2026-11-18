<?php

use Mothership\Core\ImageCache\Controller;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('ImageCache Controller', function () {

    it('fetchFromUrl has a timeout configured to prevent hanging', function () {
        $controller = new Controller;

        $reflection = new ReflectionMethod($controller, 'fetchFromUrl');

        // Verify the method exists and is callable
        expect($reflection->getName())->toBe('fetchFromUrl');

        // Read the source to verify timeout is set
        $source = file_get_contents((new ReflectionClass($controller))->getFileName());

        expect($source)->toContain("'timeout'");
    });

    it('getLogo returns a 200 response when fetchFromUrl succeeds', function () {
        $controller = new class extends Controller
        {
            protected function fetchFromUrl(string $url): string
            {
                return 'fake-image-bytes';
            }
        };

        $response = $controller->getResponse('logo', 'mothership.png');

        expect($response->getStatusCode())->toBeIn([200, 304]);
    });

    it('getLogo returns 404 when fetchFromUrl fails', function () {
        $controller = new class extends Controller
        {
            protected function fetchFromUrl(string $url): string
            {
                throw new Exception('Simulated failure');
            }
        };

        try {
            $controller->getResponse('logo', 'mothership.png');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(404);

            return;
        }

        $this->fail('Expected 404 exception was not thrown');
    });

    it('getResponse returns 404 for unknown template', function () {
        $controller = new Controller;

        try {
            $controller->getResponse('nonexistent', 'test.png');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(404);

            return;
        }

        $this->fail('Expected 404 exception was not thrown');
    });
});

// The avatar dropdown's "Profile dropdown logo image" (Mothership logo + version, with an onerror
// fallback) was replaced by the admin's name and role per the 2026-09-24 navigation redesign —
// see packages/Mothership/Admin/tests/Feature/AvatarAccountMenuTest.php for its coverage. The
// image-cache route and controller above remain in general use elsewhere and are unaffected.
