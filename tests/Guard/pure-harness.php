<?php

/**
 * Connection-free harness for Tests\Support\TestTargetGuard. Its whole dependency closure is this file and
 * tests/Support/TestTargetGuard.php: no Composer autoload, Laravel, Pest, PHPUnit, tests/bootstrap.php or PDO. Probes are
 * fakes; the only side effects are synthetic files under the system temp directory.
 *
 *   php -n tests/Guard/pure-harness.php        (exit 0 = every check held)
 */

use Tests\Support\TestTargetGuard;
use Tests\Support\TestTargetRefused;

require __DIR__.'/../Support/TestTargetGuard.php';

$passed = 0;
$failures = [];

function check(string $name, callable $body): void
{
    global $passed, $failures;

    try {
        $body();
        $passed++;
    } catch (Throwable $e) {
        $failures[] = $name.': '.$e::class.' '.$e->getMessage();
    }
}

function refused(callable $call, string $why): void
{
    try {
        $call();
    } catch (TestTargetRefused) {
        return;
    }

    throw new RuntimeException("not refused: {$why}");
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$what}: expected ".var_export($expected, true).' got '.var_export($actual, true));
    }
}

$root = realpath(sys_get_temp_dir()).'/guard-pure-'.bin2hex(random_bytes(6));
$worktree = $root.'/worktree';
$data = $root.'/pgdata';
mkdir($worktree, 0700, true);
mkdir($data, 0700, true);

register_shutdown_function(function () use ($root) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);
});

$writePid = function (array $override = []) use ($data) {
    $l = $override + [1111, $data, 1700000000, 5433, '/tmp/sock', '127.0.0.1', '0', 'ready'];
    file_put_contents($data.'/postmaster.pid', implode("\n", $l)."\n");
};
$writePid();

$file = $root.'/allowlist.json';
$writeAllowlist = function (array $override = []) use ($file, $data, $worktree): string {
    $json = json_encode($override + ['host' => '127.0.0.1', 'port' => 5433, 'database' => 'mothership_test', 'data_directory' => $data, 'worktree' => $worktree]);
    file_put_contents($file, $json);
    chmod($file, 0600);

    return hash('sha256', $json);
};
$sha = $writeAllowlist();
$allow = TestTargetGuard::loadAllowlist($file, $worktree, $sha);

$db = fn (array $conn = [], array $top = []) => $top + ['default' => 'pgsql', 'connections' => ['pgsql' => $conn + ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5433', 'database' => 'mothership_test', 'username' => 'SECRET-USER', 'password' => 'SECRET-PASSWORD']]];
$live = fn (array $o = []) => $o + ['database' => 'mothership_test', 'host' => '127.0.0.1', 'port' => 5433, 'data_directory' => $data, 'started_at' => 1700000000];
$alive = fn () => true;

check('exact target admitted, identity-only report', function () use ($db, $live, $allow, $worktree, $alive) {
    $probed = [];
    $report = TestTargetGuard::admit($db(), $allow, $worktree, function ($c) use (&$probed, $live) {
        $probed[] = $c['port'];

        return $live();
    }, $alive);
    same(['host', 'port', 'database', 'data_directory', 'postmaster_pid'], array_keys($report), 'report keys');
    same(1, count($probed), 'one fake probe');
    if (str_contains(json_encode($report), 'SECRET')) {
        throw new RuntimeException('credential in report');
    }
});

// --- allowlist custody -----------------------------------------------------------------------------------------
check('allowlist: missing / empty path', function () use ($root, $worktree, $sha) {
    refused(fn () => TestTargetGuard::loadAllowlist('', $worktree, $sha), 'empty path');
    refused(fn () => TestTargetGuard::loadAllowlist($root.'/missing.json', $worktree, $sha), 'missing');
});
check('allowlist: inside the repository', function () use ($file, $root, $sha) {
    refused(fn () => TestTargetGuard::loadAllowlist($file, $root, $sha), 'in repo');
});
check('allowlist: hash must match the frozen review', function () use ($file, $worktree, $sha) {
    refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, ''), 'no hash');
    refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, str_repeat('0', 64)), 'wrong hash');
    refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, strtoupper($sha).'x'), 'malformed hash');
});
check('allowlist: replaced content no longer matches', function () use ($file, $worktree, $sha, $writeAllowlist) {
    $writeAllowlist(['database' => 'mothership']);
    refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, $sha), 'replaced file');
    $writeAllowlist();
});
check('allowlist: symlink and non-canonical path', function () use ($file, $root, $worktree, $sha) {
    symlink($file, $root.'/link.json');
    refused(fn () => TestTargetGuard::loadAllowlist($root.'/link.json', $worktree, $sha), 'symlink');
    refused(fn () => TestTargetGuard::loadAllowlist($root.'/./allowlist.json', $worktree, $sha), 'non-canonical');
});
check('allowlist: group/other access is refused', function () use ($file, $worktree, $sha) {
    chmod($file, 0640);
    refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, $sha), 'mode 0640');
    chmod($file, 0602);
    refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, $sha), 'mode 0602');
    chmod($file, 0600);
});
foreach ([
    'extra key (secret)' => ['password' => 'x'],
    'non-loopback host'  => ['host' => '10.0.0.5'],
    'hostname'           => ['host' => 'db.internal'],
    'string port'        => ['port' => '5433'],
    'empty database'     => ['database' => ''],
    'missing data dir'   => ['data_directory' => '/nonexistent-guard-dir'],
] as $label => $override) {
    check("allowlist schema: {$label}", function () use ($writeAllowlist, $file, $worktree, $override, $label) {
        $h = $writeAllowlist($override);
        refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, $h), $label);
        $writeAllowlist();
    });
}
check('allowlist schema: missing key and invalid JSON', function () use ($file, $worktree) {
    foreach (['{"host":"127.0.0.1","port":5433,"database":"mothership_test"}', '{"host":'] as $json) {
        file_put_contents($file, $json);
        chmod($file, 0600);
        refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, hash('sha256', $json)), $json);
    }
});
$sha = $writeAllowlist();

// --- effective configuration -----------------------------------------------------------------------------------
foreach ([
    'sqlite default'             => [['default' => 'sqlite'], []],
    'other default name'         => [['default' => 'pgsql_rehearsal'], []],
    'no default'                 => [['default' => null], []],
    'url'                        => [[], ['url' => 'postgresql://other/prod']],
    'read'                       => [[], ['read' => ['host' => '10.1.1.1']]],
    'write'                      => [[], ['write' => ['host' => '10.1.1.1']]],
    'sticky'                     => [[], ['sticky' => true]],
    'unix_socket'                => [[], ['unix_socket' => '/tmp/other']],
    'connect_via_database'       => [[], ['connect_via_database' => 'mothership']],
    'connect_via_port'           => [[], ['connect_via_port' => '5432']],
    'empty connect_via_database' => [[], ['connect_via_database' => '']],
    'empty connect_via_port'     => [[], ['connect_via_port' => '']],
    'host list'                  => [[], ['host' => ['127.0.0.1', '10.1.1.1']]],
    'other host'                 => [[], ['host' => '10.1.1.1']],
    'localhost spelling'         => [[], ['host' => 'localhost']],
    'other port'                 => [[], ['port' => '5432']],
    'missing port'               => [[], ['port' => null]],
    'port as list'               => [[], ['port' => [5433]]],
    'production database'        => [[], ['database' => 'mothership']],
    'prefix-only name'           => [[], ['database' => 'mothership_test_rehearsal']],
    'sqlite driver'              => [[], ['driver' => 'sqlite']],
] as $label => [$top, $conn]) {
    check("config refused: {$label}", fn () => refused(fn () => TestTargetGuard::assertConfig($db($conn, $top), $allow, $worktree), $label));
}
check('config refused: no pgsql connection, other worktree', function () use ($allow, $worktree, $root, $db) {
    refused(fn () => TestTargetGuard::assertConfig(['default' => 'pgsql', 'connections' => []], $allow, $worktree), 'no connection');
    mkdir($root.'/other-worktree');
    refused(fn () => TestTargetGuard::assertConfig($db(), $allow, $root.'/other-worktree'), 'other worktree');
});

check('empty connect_via_* is refused before any probe or liveness call', function () use ($db, $allow, $worktree, $live) {
    foreach (['connect_via_database', 'connect_via_port'] as $key) {
        $calls = 0;
        refused(fn () => TestTargetGuard::admit($db([$key => '']), $allow, $worktree, function () use (&$calls, $live) {
            $calls++;

            return $live();
        }, function () use (&$calls) {
            $calls++;

            return true;
        }), $key);
        same(0, $calls, "calls after empty {$key}");
    }
});

// --- connector-effective configuration (checked before any PDO exists) ----------------------------------------
$conn = fn (array $o = []) => $o + ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5433', 'database' => 'mothership_test'];
check('connector config: exact target passes', function () use ($conn, $allow, $alive) {
    TestTargetGuard::assertBeforeConnect($conn(), $allow, $alive);
    TestTargetGuard::assertBeforeConnect($conn(['port' => 5433, 'username' => 'u', 'password' => 'p', 'sticky' => true, 'url' => null, 'read' => null, 'connect_via_database' => null, 'connect_via_port' => null]), $allow, $alive);
});
foreach ([
    'sqlite driver'              => ['driver' => 'sqlite', 'database' => '/tmp/x.sqlite'],
    'mysql driver'               => ['driver' => 'mysql'],
    'no driver'                  => ['driver' => null],
    'connect_via_database'       => ['connect_via_database' => 'mothership'],
    'connect_via_port'           => ['connect_via_port' => '5432'],
    'empty connect_via_database' => ['connect_via_database' => ''],
    'empty connect_via_port'     => ['connect_via_port' => ''],
    'url'                        => ['url' => 'postgresql://x/y'],
    'unix_socket'                => ['unix_socket' => '/tmp/pg'],
    'read'                       => ['read' => ['host' => '10.0.0.1']],
    'write'                      => ['write' => ['host' => '10.0.0.1']],
    'other host'                 => ['host' => '10.0.0.1'],
    'host list'                  => ['host' => ['127.0.0.1']],
    'other port'                 => ['port' => '5432'],
    'other database'             => ['database' => 'mothership'],
    'quote-injected dbname'      => ['database' => "mothership_test';host=10.0.0.1;dbname='x"],
] as $label => $o) {
    check("connector config refused: {$label}", fn () => refused(fn () => TestTargetGuard::assertBeforeConnect($conn($o), $allow, $alive), $label));
}

// --- DSN confinement and session options (every value that reaches the DSN or becomes SQL) -------------------------------
$normal = ['charset' => 'utf8', 'sslmode' => 'prefer', 'application_name' => 'mothership-test', 'search_path' => 'public', 'timezone' => 'UTC', 'isolation_level' => 'read committed', 'synchronous_commit' => 'off', 'prefix' => '', 'prefix_indexes' => true, 'name' => 'pgsql'];
check('normal charset/sslmode/session options are preserved and admitted', function () use ($conn, $normal, $allow, $alive, $db, $worktree) {
    TestTargetGuard::assertBeforeConnect($conn($normal), $allow, $alive);
    TestTargetGuard::assertConfig($db($normal), $allow, $worktree);
    foreach (['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'] as $mode) {
        TestTargetGuard::assertBeforeConnect($conn(['sslmode' => $mode]), $allow, $alive);
    }
});
foreach ([
    'sslmode host injection'       => ['sslmode' => 'disable;host=10.0.0.9;port=5432'],
    'sslmode space'                => ['sslmode' => 'disable host=10.0.0.9'],
    'sslmode newline'              => ['sslmode' => "disable\nhost=10.0.0.9"],
    'sslmode trailing newline'     => ['sslmode' => "prefer\n"],
    'charset trailing newline'     => ['charset' => "utf8\n"],
    'sslmode NUL'                  => ['sslmode' => "disable\0"],
    'sslmode unknown word'         => ['sslmode' => 'maybe'],
    'sslmode as list'              => ['sslmode' => ['disable']],
    'charset quote injection'      => ['charset' => "utf8';host=10.0.0.9;port='5432"],
    'charset other encoding'       => ['charset' => 'latin1'],
    'charset number'               => ['charset' => 8],
    'application_name quote'       => ['application_name' => "x';host=10.0.0.9;a='"],
    'application_name backslash'   => ['application_name' => 'x\\'],
    'application_name semicolon'   => ['application_name' => 'x;port=5432'],
    'application_name too long'    => ['application_name' => str_repeat('a', 64)],
    'sslcert'                      => ['sslcert' => '/tmp/cert.pem'],
    'sslkey'                       => ['sslkey' => '/tmp/key.pem'],
    'sslrootcert'                  => ['sslrootcert' => '/tmp/ca.pem'],
    'sslcert injection'            => ['sslcert' => 'x;host=10.0.0.9'],
    'sslrootcert injection'        => ['sslrootcert' => 'x;port=5432'],
    'PDO options'                  => ['options' => [PDO::ATTR_PERSISTENT => true]],
    'unknown option'               => ['target_session_attrs' => 'read-write'],
    'unknown option (service)'     => ['service' => 'prod'],
    'search_path injection'        => ['search_path' => 'public; drop schema public cascade'],
    'search_path quote'            => ['search_path' => 'public","x'],
    'schema injection'             => ['schema' => "public'; select 1"],
    'timezone injection'           => ['timezone' => "UTC'; select 1"],
    'isolation_level injection'    => ['isolation_level' => 'serializable; select 1'],
    'synchronous_commit injection' => ['synchronous_commit' => 'on; select 1'],
    'username control char'        => ['username' => "u\n"],
    'prefix injection'             => ['prefix' => "x';y"],
] as $label => $o) {
    check("DSN/session option refused before any call: {$label}", function () use ($conn, $db, $allow, $worktree, $o, $label, $live) {
        $calls = 0;
        $alive = function () use (&$calls) {
            $calls++;

            return true;
        };
        refused(fn () => TestTargetGuard::assertBeforeConnect($conn($o), $allow, $alive), "connector: {$label}");
        refused(fn () => TestTargetGuard::admit($db($o), $allow, $worktree, function () use (&$calls, $live) {
            $calls++;

            return $live();
        }, $alive), "config: {$label}");
        same(0, $calls, "probe/liveness calls for {$label}");
    });
}
check('allowlisted database name must be plain identifier characters', function () use ($writeAllowlist, $file, $worktree) {
    foreach (["mothership_test'", 'mothership test', 'a;b', 'a=b'] as $name) {
        $h = $writeAllowlist(['database' => $name]);
        refused(fn () => TestTargetGuard::loadAllowlist($file, $worktree, $h), $name);
    }
    $writeAllowlist();
});
check('session keys are exactly the SQL-producing ones', function () {
    same(['isolation_level', 'timezone', 'search_path', 'schema', 'synchronous_commit'], TestTargetGuard::SESSION_KEYS, 'SESSION_KEYS');
});

// --- postmaster pid file -----------------------------------------------------------------------------------------
foreach (['other data directory' => [1 => '/somewhere/else'], 'other port' => [3 => 5432], 'wide listen' => [5 => '*'], 'other listen' => [5 => '10.0.0.5'], 'starting' => [7 => 'starting'], 'bad pid' => [0 => 'abc']] as $label => $lines) {
    check("pid file refused: {$label}", function () use ($writePid, $allow, $lines, $label, $alive) {
        $writePid($lines);
        refused(fn () => TestTargetGuard::assertPostmaster($allow, $alive), $label);
        $writePid();
    });
}
check('pid file refused: process not running, file missing', function () use ($allow, $data, $writePid) {
    refused(fn () => TestTargetGuard::assertPostmaster($allow, fn () => false), 'dead');
    unlink($data.'/postmaster.pid');
    refused(fn () => TestTargetGuard::assertPostmaster($allow, fn () => true), 'missing');
    $writePid();
});

// --- live identity (fake probes) -----------------------------------------------------------------------------------
foreach (['other database' => ['database' => 'mothership'], 'other address' => ['host' => '10.0.0.5'], 'other port' => ['port' => 5432], 'other data dir' => ['data_directory' => '/var/lib/postgresql'], 'one second later' => ['started_at' => 1700000001], 'one second earlier' => ['started_at' => 1699999999], 'no identity' => ['database' => null, 'started_at' => null]] as $label => $o) {
    check("live refused: {$label}", fn () => refused(fn () => TestTargetGuard::admit($db(), $allow, $worktree, fn () => $live($o), $alive), $label));
}
check('live query floors the postmaster start and fake probe failure refuses', function () use ($db, $allow, $worktree, $alive) {
    if (! str_contains(TestTargetGuard::LIVE_QUERY, 'floor(extract(epoch from pg_postmaster_start_time()))::bigint')) {
        throw new RuntimeException('LIVE_QUERY must floor the postmaster start epoch');
    }
    refused(fn () => TestTargetGuard::admit($db(), $allow, $worktree, function () {
        throw new TestTargetRefused('probe failed');
    }, $alive), 'probe failure');
});

// --- required() / lane flags -----------------------------------------------------------------------------------------
check('lane is required by either env variable', function () {
    putenv('MOTHERSHIP_REQUIRE_TARGET_GUARD');
    putenv('MOTHERSHIP_TEST_TARGET_ALLOWLIST');
    same(false, TestTargetGuard::required(), 'neither set');
    putenv('MOTHERSHIP_REQUIRE_TARGET_GUARD=1');
    same(true, TestTargetGuard::required(), 'require flag');
    putenv('MOTHERSHIP_REQUIRE_TARGET_GUARD');
    putenv('MOTHERSHIP_TEST_TARGET_ALLOWLIST=/x');
    same(true, TestTargetGuard::required(), 'allowlist path alone');
    putenv('MOTHERSHIP_TEST_TARGET_ALLOWLIST');
});

// --- dependency closure ---------------------------------------------------------------------------------------------
check('closure: only this harness and the guard are loaded, and the guard names no connection API', function () {
    $included = array_map('realpath', get_included_files());
    $expected = [realpath(__FILE__), realpath(__DIR__.'/../Support/TestTargetGuard.php')];
    sort($included);
    sort($expected);
    same($expected, $included, 'included files');

    foreach (token_get_all((string) file_get_contents(__DIR__.'/../Support/TestTargetGuard.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) && preg_match('/^\\\\?(PDO|pg_connect|fsockopen|stream_socket_client|curl_init|exec|shell_exec|proc_open|system|passthru)$/i', $token[1])) {
            throw new RuntimeException('guard references '.$token[1]);
        }
    }
});

// CI admission mode: proof file, runner environment and live identity, all pure.
$temp = $root.'/runner-temp';
mkdir($temp, 0700);
$container = str_repeat('ab', 32);
$proofFile = $temp.'/proof.json';
$proofBody = fn (array $o = []) => $o + ['container_id' => $container, 'run_id' => '9001', 'run_attempt' => '2', 'workspace' => $worktree, 'host_port' => 49153, 'container_ips' => ['172.18.0.2'], 'database' => 'mothership_test_ci', 'data_directory' => '/var/lib/postgresql/data', 'tmpfs' => true, 'started_at' => 1760000000, 'system_identifier' => '7400000000000000001'];
$ciEnv = function (array $proof = [], array $env = []) use ($proofFile, $proofBody, $container, $worktree, $temp) {
    file_put_contents($proofFile, json_encode($proofBody($proof)));
    chmod($proofFile, 0600);

    return $env + ['MOTHERSHIP_CI_PROOF' => $proofFile, 'MOTHERSHIP_CI_PROOF_SHA256' => hash_file('sha256', $proofFile), 'MOTHERSHIP_CI_SERVICE_ID' => $container, 'MOTHERSHIP_CI_SERVICE_PORT' => '49153', 'GITHUB_ACTIONS' => 'true', 'GITHUB_RUN_ID' => '9001', 'GITHUB_RUN_ATTEMPT' => '2', 'GITHUB_WORKSPACE' => $worktree, 'RUNNER_TEMP' => $temp];
};

check('ci: a consistent proof yields the allowlist shape', function () use ($ciEnv, $worktree) {
    $ci = TestTargetGuard::loadCiProof($ciEnv(), $worktree);
    same(49153, $ci['port'], 'port');
    same('mothership_test_ci', $ci['database'], 'database');
    same(0, TestTargetGuard::assertPostmaster($ci)['pid'], 'no local pid file is read for the container');
});

check('ci: ambient Actions variables without a proof are refused', function () use ($ciEnv, $worktree) {
    refused(fn () => TestTargetGuard::loadCiProof(['MOTHERSHIP_CI_PROOF' => '', 'MOTHERSHIP_CI_PROOF_SHA256' => ''] + $ciEnv(), $worktree), 'no proof');
});

check('ci: every disagreement with the job is refused', function () use ($ciEnv, $worktree) {
    foreach ([
        'attempt'         => [[], ['GITHUB_RUN_ATTEMPT' => '1']],
        'container'       => [[], ['MOTHERSHIP_CI_SERVICE_ID' => str_repeat('cd', 32)]],
        'port'            => [['host_port' => 5433], []],
        'database'        => [['database' => 'mothership_test'], []],
        'tmpfs'           => [['tmpfs' => false], []],
        'outside actions' => [[], ['GITHUB_ACTIONS' => 'false']],
        'hash'            => [[], ['MOTHERSHIP_CI_PROOF_SHA256' => str_repeat('0', 64)]],
    ] as $why => [$proof, $env]) {
        refused(fn () => TestTargetGuard::loadCiProof($ciEnv($proof, $env), $worktree), $why);
    }
});

check('ci: a proof open to group or others is refused', function () use ($ciEnv, $worktree, $proofFile) {
    $env = $ciEnv();
    chmod($proofFile, 0640);
    refused(fn () => TestTargetGuard::loadCiProof($env, $worktree), 'mode 0640');
});

check('ci: the live server must be the one the proof recorded', function () use ($ciEnv, $worktree) {
    $ci = TestTargetGuard::loadCiProof($ciEnv(), $worktree);
    $pm = TestTargetGuard::assertPostmaster($ci);
    $row = ['database' => 'mothership_test_ci', 'host' => '172.18.0.2', 'port' => 5432, 'data_directory' => '/var/lib/postgresql/data', 'started_at' => 1760000000, 'system_identifier' => '7400000000000000001'];
    TestTargetGuard::assertLive($row, $ci, $pm);

    foreach (['host' => '127.0.0.1', 'port' => 5433, 'data_directory' => '/tmp/pg', 'started_at' => 1, 'system_identifier' => '1', 'database' => 'mothership'] as $key => $value) {
        refused(fn () => TestTargetGuard::assertLive([$key => $value] + $row, $ci, $pm), $key);
    }
});

echo "pure harness: {$passed} checks passed, ".count($failures)." failed\n";

foreach ($failures as $failure) {
    echo "FAIL {$failure}\n";
}

exit($failures === [] ? 0 : 1);
