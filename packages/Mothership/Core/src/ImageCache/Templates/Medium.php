<?php

namespace Mothership\Core\ImageCache\Templates;

use Mothership\Core\ImageCache\ImageCache;

class Medium
{
    /**
     * Apply the filter to the image cache pipeline.
     */
    public function applyFilter(ImageCache $image): ImageCache
    {
        return $image->cover(300, 300);
    }
}
