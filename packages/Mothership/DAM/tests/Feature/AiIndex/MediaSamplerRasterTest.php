<?php

declare(strict_types=1);

use Mothership\DAM\AiIndex\MediaSampler;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function () {
    $this->png = tempnam(sys_get_temp_dir(), 'damai_test_').'.png';
    $image = imagecreatetruecolor(300, 200);
    imagefilledrectangle($image, 0, 0, 299, 199, imagecolorallocate($image, 200, 40, 40));
    imagepng($image, $this->png);
    config(['ai-index.image_edge' => 100]);
});

afterEach(function () {
    @unlink($this->png);
    @unlink(substr($this->png, 0, -4));
});

it('scales an image under the pixel budget with GD', function () {
    config(['ai-index.gd_max_pixels' => 1_000_000]);

    $frames = app(MediaSampler::class)->rasterFrames($this->png);

    expect($frames)->toHaveCount(1)
        ->and(getimagesizefromstring($frames[0])[0])->toBe(100)
        ->and(getimagesizefromstring($frames[0])[1])->toBe(67);
});

// A 37.7 MP JPEG decoded in GD ran the 512 MB ai-index worker out of memory and stopped it (2026-10-09).
it('scales an image over the pixel budget out of process with ffmpeg', function () {
    if (! (new ExecutableFinder)->find('ffmpeg')) {
        $this->markTestSkipped('ffmpeg is not installed.');
    }

    config(['ai-index.gd_max_pixels' => 1_000]);

    $frames = app(MediaSampler::class)->rasterFrames($this->png);

    expect($frames)->toHaveCount(1)
        ->and(getimagesizefromstring($frames[0])['mime'])->toBe('image/jpeg')
        ->and(getimagesizefromstring($frames[0])[0])->toBe(100)
        ->and(getimagesizefromstring($frames[0])[1])->toBeIn([66, 67]);
});
