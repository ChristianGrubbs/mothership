<?php

namespace Mothership\DAM\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\CreatesApplication;

/**
 * Deliberately NOT DatabaseTransactions (unlike DamTestCase and every other DAM feature test).
 *
 * A test that forks a real second OS process (SphereSeamConcurrentImportTest) needs its fixtures
 * genuinely committed: PostgreSQL's row lock only means anything across two independent
 * connections, and a forked child's fresh connection would never see a row still sitting inside
 * the parent's open, uncommitted DatabaseTransactions wrapper — every other DAM test's isolation
 * mechanism is exactly what would hide the race this exists to reproduce. Tests using this base
 * are responsible for their own cleanup; nothing here rolls anything back.
 */
abstract class DamConcurrencyTestCase extends BaseTestCase
{
    use CreatesApplication;
}
