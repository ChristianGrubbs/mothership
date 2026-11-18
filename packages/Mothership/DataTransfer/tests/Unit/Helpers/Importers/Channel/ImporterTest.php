<?php

use Mothership\Category\Models\Category;
use Mothership\Core\Models\Channel;
use Mothership\DataTransfer\Helpers\Error;
use Mothership\DataTransfer\Helpers\Import;
use Mothership\DataTransfer\Helpers\Importers\Channel\Importer;
use Mothership\DataTransfer\Models\JobTrack;
use Mothership\DataTransfer\Models\JobTrackBatch;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

it('can validate a valid row for append action', function () {
    $category = Category::factory()->create();
    $locale = core()->getCurrentLocale();
    $currency = core()->getBaseCurrency();

    $importer = app(Importer::class);

    $jobTrack = JobTrack::factory()->make(['action' => Import::ACTION_APPEND]);
    $importer->setImport($jobTrack);
    $importer->setErrorHelper(app(Error::class));

    $rowData = [
        'code'          => 'test-channel-import',
        'name'          => 'Test Channel',
        'root_category' => $category->code,
        'locales'       => $locale->code,
        'currencies'    => $currency->code,
        'locale'        => $locale->code,
    ];

    $isValid = $importer->validateRow($rowData, 1);

    expect($isValid)->toBeTrue();
});

it('can skip an invalid row with missing fields', function () {
    $importer = app(Importer::class);

    $jobTrack = JobTrack::factory()->make(['action' => Import::ACTION_APPEND]);
    $importer->setImport($jobTrack);
    $importer->setErrorHelper(app(Error::class));

    $rowData = [
        'code' => 'test-channel-import',
    ];

    $isValid = $importer->validateRow($rowData, 1);

    expect($isValid)->toBeFalse();
});

it('can append new channels', function () {
    $category = Category::factory()->create();
    $locale = core()->getCurrentLocale();
    $currency = core()->getBaseCurrency();

    $importer = app(Importer::class);

    $batchData = [
        [
            'code'          => 'test-channel-import',
            'name'          => 'Test Channel',
            'root_category' => $category->code,
            'locales'       => $locale->code,
            'currencies'    => $currency->code,
            'locale'        => $locale->code,
        ],
    ];

    $jobTrack = JobTrack::factory()->create(['action' => Import::ACTION_APPEND]);
    $jobTrackBatch = JobTrackBatch::factory()->create([
        'data'         => $batchData,
        'job_track_id' => $jobTrack->id,
    ]);

    $importer->importBatch($jobTrackBatch);

    assertDatabaseHas('channels', [
        'code'             => 'test-channel-import',
        'root_category_id' => $category->id,
    ]);

    assertDatabaseHas('channel_translations', [
        'name'   => 'Test Channel',
        'locale' => $locale->code,
    ]);
});

it('can delete channels', function () {
    $channel = Channel::factory()->create(['code' => 'test-channel-delete']);

    $importer = app(Importer::class);

    $batchData = [
        [
            'code' => 'test-channel-delete',
        ],
    ];

    $jobTrack = JobTrack::factory()->create(['action' => Import::ACTION_DELETE]);
    $jobTrackBatch = JobTrackBatch::factory()->create([
        'data'         => $batchData,
        'job_track_id' => $jobTrack->id,
    ]);

    $importer->importBatch($jobTrackBatch);

    assertDatabaseMissing('channels', [
        'code' => 'test-channel-delete',
    ]);
});
