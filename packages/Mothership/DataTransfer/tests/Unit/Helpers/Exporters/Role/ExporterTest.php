<?php

namespace Mothership\DataTransfer\Tests\Unit\Helpers\Exporters\Role;

use Illuminate\Support\Facades\Event;
use Mothership\DataTransfer\Helpers\Exporters\Role\Exporter;
use Mothership\DataTransfer\Jobs\Export\File\FlatItemBuffer;
use Mothership\DataTransfer\Models\JobTrack;
use Mothership\DataTransfer\Models\JobTrackBatch;
use Mothership\DataTransfer\Repositories\JobTrackBatchRepository;

describe('Role Exporter', function () {
    it('can prepare roles data for export', function () {
        $roles = [
            [
                'id'              => 1,
                'name'            => 'Administrator',
                'description'     => 'Administrator role',
                'permission_type' => 'all',
                'permissions'     => null,
            ],
            [
                'id'              => 2,
                'name'            => 'Manager',
                'description'     => 'Manager role',
                'permission_type' => 'custom',
                'permissions'     => ['dashboard', 'catalog'],
            ],
        ];

        $batch = new JobTrackBatch([
            'data' => $roles,
        ]);

        $exporter = app(Exporter::class);
        $preparedData = $exporter->prepareRoles($batch);

        expect($preparedData)->toBeArray();
        expect($preparedData)->toHaveCount(2);

        expect($preparedData[0]['name'])->toBe('Administrator');
        expect($preparedData[0]['permission_type'])->toBe('all');
        expect($preparedData[0]['permissions'])->toBe('');

        expect($preparedData[1]['name'])->toBe('Manager');
        expect($preparedData[1]['permission_type'])->toBe('custom');
        expect($preparedData[1]['permissions'])->toBe('dashboard,catalog');
    });

    it('dispatches events during exportBatch', function () {
        Event::fake();

        $jobTrack = JobTrack::factory()->export()->create();

        $batch = JobTrackBatch::factory()->create([
            'job_track_id' => $jobTrack->id,
            'data'         => [],
        ]);

        $fileBuffer = mock(FlatItemBuffer::class);
        $fileBuffer->shouldReceive('initialize')->andReturn(mock(FlatItemBuffer::class));
        $fileBuffer->shouldReceive('getFilePath')->andReturn('dummy/path.csv');

        $exporter = new Exporter(
            app(JobTrackBatchRepository::class),
            $fileBuffer
        );
        $exporter->setExport($jobTrack);

        $buffer = mock(FlatItemBuffer::class);
        $buffer->shouldReceive('write')->once();

        $exporter->setExportBuffer($buffer);

        $exporter->exportBatch($batch, 'dummy/path.csv');

        Event::assertDispatched('data_transfer.exports.batch.export.before');
        Event::assertDispatched('data_transfer.exports.batch.export.after');
    });
});
