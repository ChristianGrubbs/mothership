<?php

namespace Mothership\DAM\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamTestCase;
use Mothership\User\Models\Admin;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;

/**
 * The blob half of the residue check, on the paths where deleting a blob does not work.
 *
 * The sweep used to delete the file object's row whatever happened to its bytes: a delete that returned false
 * was ignored and a delete that threw was swallowed as "the disk was torn down anyway". The counts then came
 * back level with the bytes still on disk, which is the residue the check exists to catch (Codex review of
 * PR #74 round 5, finding 1).
 *
 * These cases drive the concern directly, with disks that fail in each of those ways, so the report is held to
 * naming the blob rather than to any particular disk driver. They run inside the DatabaseTransactions wrapper —
 * the rows they insert are rolled back — and on every driver, since nothing here needs a second session.
 */
final class CommittedResidueSweepTest extends DamTestCase
{
    use AssertsNoCommittedResidue;

    public function test_a_delete_that_returns_false_and_leaves_the_file_is_reported(): void
    {
        $this->recordResidueBaseline();
        $this->givenAFileObjectOn(disk: $this->refusingDisk(present: true), path: 'objects/refused.jpg');

        $this->assertResidueReports('refused.jpg');
    }

    public function test_a_delete_that_throws_is_reported(): void
    {
        $this->recordResidueBaseline();
        $this->givenAFileObjectOn(disk: $this->throwingDisk(), path: 'objects/exploding.jpg');

        // An exception is no evidence that the bytes went anywhere, so it is reported with its reason rather
        // than read as "the disk is gone".
        $this->assertResidueReports('exploding.jpg', 'the disk gave up');
    }

    public function test_a_delete_that_returns_false_for_a_file_already_gone_is_not_residue(): void
    {
        $this->recordResidueBaseline();
        $this->givenAFileObjectOn(disk: $this->refusingDisk(present: false), path: 'objects/already-gone.jpg');

        // Some drivers report false for a path that is not there. Absence is what decides.
        $this->assertNoCommittedResidue();
    }

    public function test_a_blob_that_really_goes_away_is_not_reported_and_is_gone(): void
    {
        Storage::fake('dam-residue-real');
        Storage::disk('dam-residue-real')->put('objects/real.jpg', 'bytes');

        $this->recordResidueBaseline();
        $this->givenAFileObjectOn(disk: 'dam-residue-real', path: 'objects/real.jpg');

        $this->assertNoCommittedResidue();
        $this->assertFalse(Storage::disk('dam-residue-real')->exists('objects/real.jpg'), 'the sweep left the blob behind');
    }

    public function test_a_notification_delivered_to_a_surviving_admin_is_swept_and_earlier_ones_are_kept(): void
    {
        $survivor = Admin::factory()->create(['status' => 1]);
        $keptNotification = $this->givenANotificationFor(adminId: (int) $survivor->id);
        $this->recordResidueBaseline();
        $before = ['notifications' => DB::table('notifications')->count(), 'user_notifications' => DB::table('user_notifications')->count()];

        // What a committed delivery leaves for an admin the test does not own (the install's seeded full admins).
        $fixtureNotification = $this->givenANotificationFor(adminId: (int) $survivor->id);

        $this->assertNoCommittedResidue();

        $this->assertSame($before, ['notifications' => DB::table('notifications')->count(), 'user_notifications' => DB::table('user_notifications')->count()], 'the sweep left a delivered notification or its pivot row behind');
        $this->assertTrue(DB::table('notifications')->where('id', $keptNotification)->exists(), 'the sweep removed a notification that existed before the test');
        $this->assertFalse(DB::table('notifications')->where('id', $fixtureNotification)->exists(), 'the sweep kept the fixture notification');
        $this->assertSame(1, DB::table('user_notifications')->where('admin_id', $survivor->id)->count(), 'the earlier pivot row must be the only one left for the surviving admin');
    }

    /** A notification and its pivot row for an existing admin, written the way a committed delivery writes them. */
    private function givenANotificationFor(int $adminId): int
    {
        $notificationId = DB::table('notifications')->insertGetId(['type' => 'dam_feedback_created', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_notifications')->insert(['admin_id' => $adminId, 'notification_id' => $notificationId, 'read' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return (int) $notificationId;
    }

    /** Inserts one file object above the baseline, so the sweep treats it as this test's own. */
    private function givenAFileObjectOn(string $disk, string $path): void
    {
        DB::table('dam_file_objects')->insert([
            'sha256'     => str_pad(dechex(random_int(0, PHP_INT_MAX)), 64, '0', STR_PAD_LEFT),
            'size'       => 5,
            'disk'       => $disk,
            'path'       => $path,
            'mime_type'  => 'image/jpeg',
            'created_at' => now(),
        ]);
    }

    /** That the residue check fails, and that its message names the blob it could not remove. */
    private function assertResidueReports(string $path, ?string $reason = null): void
    {
        try {
            $this->assertNoCommittedResidue();
        } catch (ExpectationFailedException $reported) {
            $this->assertStringContainsString($path, $reported->getMessage());
            $this->assertStringContainsString('blobs', $reported->getMessage());

            if ($reason !== null) {
                $this->assertStringContainsString($reason, $reported->getMessage());
            }

            return;
        }

        $this->fail('the residue check passed with a blob it could not delete');
    }

    /**
     * A disk whose delete() reports failure, with the file either still there or already gone.
     *
     * Duck-typed rather than a Filesystem implementation: Storage::set() takes whatever it is given, and the
     * sweep calls exactly these two methods.
     */
    private function refusingDisk(bool $present): string
    {
        $name = 'dam-residue-refusing-'.($present ? 'present' : 'absent');

        Storage::set($name, new class($present)
        {
            public function __construct(private readonly bool $present) {}

            public function delete($paths): bool
            {
                return false;
            }

            public function exists($path): bool
            {
                return $this->present;
            }
        });

        return $name;
    }

    /** A disk that throws on delete, as a disk removed from the configuration would. */
    private function throwingDisk(): string
    {
        Storage::set('dam-residue-throwing', new class
        {
            public function delete($paths): bool
            {
                throw new RuntimeException('the disk gave up');
            }

            public function exists($path): bool
            {
                return true;
            }
        });

        return 'dam-residue-throwing';
    }
}
