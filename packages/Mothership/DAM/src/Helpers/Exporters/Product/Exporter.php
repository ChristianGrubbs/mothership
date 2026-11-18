<?php

namespace Mothership\DAM\Helpers\Exporters\Product;

use Mothership\Attribute\Repositories\AttributeRepository;
use Mothership\Core\Repositories\ChannelRepository;
use Mothership\DAM\Helpers\Exporters\Product\Concerns\ExportsAssetAttributes;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DataTransfer\Helpers\Exporters\Product\Exporter as BaseExporter;
use Mothership\DataTransfer\Helpers\Sources\Export\ProductSource;
use Mothership\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Mothership\DataTransfer\Repositories\JobTrackBatchRepository;

class Exporter extends BaseExporter
{
    use ExportsAssetAttributes;

    public function __construct(
        JobTrackBatchRepository $exportBatchRepository,
        FileExportFileBuffer $exportFileBuffer,
        ChannelRepository $channelRepository,
        AttributeRepository $attributeRepository,
        ProductSource $productSource,
        protected AssetRepository $assetRepository,
    ) {
        parent::__construct(
            $exportBatchRepository,
            $exportFileBuffer,
            $channelRepository,
            $attributeRepository,
            $productSource
        );
    }
}
