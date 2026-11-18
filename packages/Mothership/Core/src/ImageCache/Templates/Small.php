<?php

namespace Mothership\Core\ImageCache\Templates;

use Mothership\Core\ImageCache\ImageCache;

class Small
{
    /**
     * Apply the filter to the image cache pipeline.
     */
    public function applyFilter(ImageCache $image): ImageCache
    {
        return $image->cover(100, 100);
    }
}
