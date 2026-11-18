<?php

namespace Mothership\Product\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Mothership\Product\Repositories\ProductRepository;

class MassUpdateProductsStatus implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int>  $productIds
     */
    public function __construct(protected array $productIds, protected bool $status) {}

    public function handle(ProductRepository $productRepository): void
    {
        $productRepository->massUpdateStatus($this->productIds, $this->status);
    }
}
