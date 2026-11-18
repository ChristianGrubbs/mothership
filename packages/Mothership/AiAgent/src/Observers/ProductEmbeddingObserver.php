<?php

namespace Mothership\AiAgent\Observers;

use Mothership\AiAgent\Jobs\DeleteProductEmbeddingJob;
use Mothership\AiAgent\Jobs\IndexProductEmbeddingsJob;
use Mothership\AiAgent\Services\VectorStore\ProductEmbeddingIndex;
use Mothership\Product\Models\Product as Products;

/**
 * Keeps the persistent product embedding index in sync with product changes.
 *
 * Mirrors the gating style of the ElasticSearch package's product observer:
 * registered unconditionally, but every hook checks the feature toggle so no
 * work happens (and no data leaves the database) while the store is disabled.
 */
class ProductEmbeddingObserver
{
    public function __construct(protected ProductEmbeddingIndex $productEmbeddingIndex) {}

    /**
     * Queue embedding indexing for a newly created product.
     */
    public function created(Products $product): void
    {
        if ($this->productEmbeddingIndex->isEnabled()) {
            dispatch(new IndexProductEmbeddingsJob([$product->id]));
        }
    }

    /**
     * Queue embedding re-indexing for an updated product. Skipped entirely
     * when no embeddable column changed, so bulk status/stock updates don't
     * flood the queue; the job additionally skips unchanged content hashes.
     */
    public function updated(Products $product): void
    {
        if (! $product->wasChanged(['values', 'sku'])) {
            return;
        }

        if ($this->productEmbeddingIndex->isEnabled()) {
            dispatch(new IndexProductEmbeddingsJob([$product->id]));
        }
    }

    /**
     * Queue removal of a deleted product's embedding document.
     */
    public function deleted(Products $product): void
    {
        if ($this->productEmbeddingIndex->isEnabled()) {
            dispatch(new DeleteProductEmbeddingJob($product->id));
        }
    }
}
