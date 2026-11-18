<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mothership\DAM\Vectorization\Allowance;
use Mothership\DAM\Vectorization\Requests;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing')
    || DB::getDriverName() !== 'pgsql'
    || ! str_starts_with(DB::connection()->getDatabaseName(), 'mothership_test_')) {
    fwrite(STDERR, "Refusing non-disposable test database.\n");
    exit(2);
}

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl is required.\n");
    exit(2);
}

Queue::fake();
config(['vectorization.owner_admin_id' => null]);
$admin = Admin::factory()->create();
$adminId = $admin->id;
app(Allowance::class)->set(adminId: $adminId, remaining: 1, revision: 0);
DB::disconnect();
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

if ($pair === false) {
    throw new RuntimeException('Socket pair unavailable.');
}

$pid = pcntl_fork();

if ($pid === -1) {
    throw new RuntimeException('Cannot fork race test.');
}

if ($pid === 0) {
    fclose($pair[0]);
    stream_set_timeout($pair[1], 15);

    if (fread($pair[1], 1) !== 'G') {
        exit(3);
    }

    fwrite($pair[1], 'R');

    try {
        DB::statement("SET lock_timeout = '10s'");
        app(Requests::class)->submit(
            adminId: $adminId,
            requestId: (string) Str::uuid(),
            inputPath: 'race/b',
            sha: hash('sha256', 'b'),
        );
        fwrite($pair[1], 'accepted');
        exit(1);
    } catch (HttpException $error) {
        fwrite($pair[1], (string) $error->getStatusCode());
        exit($error->getStatusCode() === 403 ? 0 : 1);
    }
}

fclose($pair[1]);
stream_set_timeout($pair[0], 20);

try {
    DB::beginTransaction();
    DB::table('dam_vectorization_allowances')->where('admin_id', $adminId)->lockForUpdate()->first();
    fwrite($pair[0], 'G');

    if (fread($pair[0], 1) !== 'R') {
        throw new RuntimeException('Child did not start.');
    }

    app(Requests::class)->submit(
        adminId: $adminId,
        requestId: (string) Str::uuid(),
        inputPath: 'race/a',
        sha: hash('sha256', 'a'),
    );
    DB::commit();
    $reply = stream_get_contents($pair[0]);
    pcntl_waitpid($pid, $status);
    $wallet = app(Allowance::class)->read(adminId: $adminId);

    if ($reply !== '403'
        || pcntl_wexitstatus($status) !== 0
        || $wallet['remaining'] !== 0
        || $wallet['reserved'] !== 1
        || DB::table('dam_vectorizations')->where('admin_id', $adminId)->count() !== 1) {
        throw new RuntimeException('Concurrent reservation invariant failed.');
    }

    echo "race passed\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    fclose($pair[0]);
    // Only this probe's own fixture in the verified disposable database is removed.
    Admin::whereKey($adminId)->delete();
}
