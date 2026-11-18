<?php

use Mothership\AdminApi\ApiDataSource\Catalog\ProductDataSource;
use Mothership\Attribute\Repositories\AttributeFamilyRepository;
use Mothership\Attribute\Repositories\AttributeRepository;
use Mothership\Completeness\Repositories\ProductCompletenessScoreRepository;
use Mothership\Core\Repositories\ChannelRepository;
use Mothership\DataTransfer\Helpers\Exporters\Product\Exporter;
use Mothership\DataTransfer\Helpers\Sources\Export\ProductSource;
use Mothership\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Mothership\DataTransfer\Repositories\JobTrackBatchRepository;
use Mothership\Product\Contracts\VariantValueResolver;
use Mothership\Product\Repositories\ProductAssociationRepository;
use Mothership\Product\Repositories\ProductRepository;

it('constructs the product exporter with the constructor signature published in 3.0.0', function () {
    $exporter = new Exporter(
        app(JobTrackBatchRepository::class),
        app(FileExportFileBuffer::class),
        app(ChannelRepository::class),
        app(AttributeRepository::class),
        app(ProductSource::class),
    );

    expect($exporter)->toBeInstanceOf(Exporter::class);
});

it('constructs the product api data source with the constructor signature published in 3.0.0', function () {
    $dataSource = new ProductDataSource(
        app(ProductRepository::class),
        app(AttributeFamilyRepository::class),
        app(ProductCompletenessScoreRepository::class),
        app(ProductAssociationRepository::class),
    );

    expect($dataSource)->toBeInstanceOf(ProductDataSource::class);
});

it('resolves the variant value resolver from the container when it was not injected', function () {
    $exporter = new Exporter(
        app(JobTrackBatchRepository::class),
        app(FileExportFileBuffer::class),
        app(ChannelRepository::class),
        app(AttributeRepository::class),
        app(ProductSource::class),
    );

    $resolver = (function () {
        return $this->variantValueResolver();
    })->call($exporter);

    expect($resolver)->toBeInstanceOf(VariantValueResolver::class);
});
