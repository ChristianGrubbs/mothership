<?php

namespace Mothership\DAM\Tests\Concerns;

/**
 * What a committed test owes the database it ran against: the shared access-model state put back, and no row or
 * blob of its own left behind.
 *
 * Both are unconditional, and neither may be lost because the other failed, so they are called from one place
 * that every teardown path reaches — including the early return a partial setUp takes.
 */
trait CommittedFixtureCleanup
{
    use AssertsNoCommittedResidue;
    use RestoresAccessModelState;

    /**
     * The cleanup that runs however the test ended. The restore is attempted first and the residue check runs
     * even if it threw, so a failure in one is reported without hiding the other.
     */
    protected function cleanUpCommittedFixtures(): void
    {
        try {
            $this->restoreAccessModelState();
        } finally {
            $this->assertNoCommittedResidue();
        }
    }
}
