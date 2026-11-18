<?php

use PHPUnit\TextUI\CliArguments\Builder;
use Symfony\Component\Yaml\Yaml;

/*
 * Every DAM *Test.php must be run by a step of the `pest` job, the PostgreSQL leg that runs nightly.
 * The check itself runs in the SQLite job on every pull request, since it only parses the YAML.
 *
 * The workflow is parsed as YAML and only the `run:` text of steps that run on the required
 * leg is read: no `if:`, an always-runs condition or the PostgreSQL leg. A file counts only as
 * a whole argument of a command that starts a line with `vendor/bin/pest`, up to the first
 * `;`, `&`, `|` or `#`, outside heredoc bodies. Step names, comments, `echo <path>`,
 * `--log-junit=<path>`, `<path>.bak`, heredoc text and advisory-only or disabled steps never
 * count. Anything unusual fails closed: the file is reported unlisted rather than credited.
 *
 * Usage: php .github/scripts/check-dam-test-gate.php [--self-test] [repository-root]
 */

const DAM_TEST_PATH = '#^packages/Mothership/DAM/tests/[A-Za-z0-9_/]+Test\.php$#';

/**
 * Whether an option takes its value as the next argument: PHPUnit's own required-value options,
 * read from its argument parser so the list cannot drift, plus Pest's --processes. Options with
 * no value or an optional one (--testdox-summary, --coverage-text) leave the next argument alone.
 */
function damGateTakesValue(string $argument): bool
{
    static $options = null;

    if ($options === null) {
        $builder = Builder::class;
        $options = ['--processes' => true];

        foreach ((new ReflectionClassConstant($builder, 'LONG_OPTIONS'))->getValue() as $long) {
            if (preg_match('/^([a-z0-9-]+)=$/', $long, $name)) {
                $options['--'.$name[1]] = true;
            }
        }

        preg_match_all('/([a-z]):(?!:)/', (new ReflectionClassConstant($builder, 'SHORT_OPTIONS'))->getValue(), $short);

        foreach ($short[1] as $letter) {
            $options['-'.$letter] = true;
        }
    }

    return isset($options[$argument]);
}

/** Conditions under which a step still runs on the required (PostgreSQL) leg. */
function damGateRequiredLeg(mixed $condition): bool
{
    if ($condition === null || $condition === true) {
        return true;
    }

    $bare = trim(preg_replace('/^\$\{\{\s*(.*?)\s*\}\}$/s', '$1', trim((string) $condition)));

    // The nightly job's daytime guard (pest_tests.yml, 2026-10-12) ANDs this clause onto every
    // step; it is true on every run that executes tests, so it does not narrow the leg.
    $guard = "steps.guard.outputs.run == 'true'";

    if ($bare === $guard) {
        return true;
    }

    if (str_ends_with($bare, ' && '.$guard)) {
        $bare = trim(substr($bare, 0, -strlen(' && '.$guard)));
    }

    return in_array($bare, ['!cancelled()', 'always()', 'success()', "matrix.connection == 'pgsql'"], true);
}

/** @return array<string, true> DAM test paths the `pest` job runs on its required leg. */
function damGateNamed(array $workflow): array
{
    $named = [];

    foreach ($workflow['jobs']['pest']['steps'] ?? [] as $step) {
        if (! is_array($step) || ! damGateRequiredLeg($step['if'] ?? null)) {
            continue;
        }

        $heredoc = null;

        foreach (preg_split('/\R/', (string) ($step['run'] ?? '')) as $line) {
            if ($heredoc !== null) {
                if (trim($line) === $heredoc) {
                    $heredoc = null;
                }

                continue;
            }

            // A heredoc starts only in code: a `<<` inside quoted text or a shell comment does not
            // count. Quoted spans are masked in place so offsets still line up with the line.
            $masked = preg_replace_callback('/\'[^\']*\'|"[^"]*"/', fn ($quoted) => str_repeat('x', strlen($quoted[0])), $line);
            $comment = preg_match('/(^|\s)#/', $masked, $hash, PREG_OFFSET_CAPTURE) ? $hash[0][1] : PHP_INT_MAX;

            if (preg_match('/<<-?\s*[\'"]?([A-Za-z_][A-Za-z0-9_]*)[\'"]?/', $line, $marker, PREG_OFFSET_CAPTURE)
                && $marker[0][1] < $comment
                && substr($masked, $marker[0][1], 2) === '<<') {
                $heredoc = $marker[1][0];
            }

            if (! preg_match('#^\s*vendor/bin/pest(\s|$)#', $line)) {
                continue;
            }

            $command = preg_split('/[;&|#]/', $line)[0];
            $takesValue = false;

            foreach (preg_split('/\s+/', trim($command)) as $argument) {
                $argument = trim($argument, '\'"');

                // The argument after a value-taking option (`--log-junit <file>`) is that value.
                if ($takesValue) {
                    $takesValue = false;

                    continue;
                }

                if (damGateTakesValue($argument)) {
                    $takesValue = true;

                    continue;
                }

                if (preg_match(DAM_TEST_PATH, $argument)) {
                    $named[$argument] = true;
                }
            }
        }
    }

    return $named;
}

/** Each decoy must not count; each real invocation must. */
function damGateSelfTest(): int
{
    $file = 'packages/Mothership/DAM/tests/Feature/ExampleTest.php';
    $pest = 'vendor/bin/pest --colors=always';
    $cases = [
        'plain run'          => ["- run: $pest $file", true],
        'run block'          => ["- run: |\n    set -e\n    $pest $file", true],
        'not cancelled'      => ["- if: \${{ !cancelled() }}\n  run: $pest $file", true],
        'pgsql leg'          => ["- if: matrix.connection == 'pgsql'\n  run: $pest $file", true],
        'two files'          => ["- run: $pest packages/Mothership/DAM/tests/Feature/OtherTest.php $file", true],
        'step name'          => ["- name: $pest $file\n  run: 'true'", false],
        'multi-line name'    => ["- name: |\n    $pest $file\n  run: 'true'", false],
        'inline comment'     => ["- run: true # $pest $file", false],
        'trailing comment'   => ["- run: $pest packages/Mothership/DAM/tests/Feature/OtherTest.php # $file", false],
        'echo after'         => ["- run: $pest --version; echo $file", false],
        'option value'       => ["- run: $pest --version --log-junit=$file", false],
        'suffix'             => ["- run: $pest $file.bak", false],
        'quoted hash first'  => ["- run: |\n    printf ' # x'; $pest $file", false],
        'heredoc'            => ["- run: |\n    cat <<'EOF'\n    $pest $file\n    EOF", false],
        'single quoted'      => ["- run: $pest '$file'", true],
        'double quoted'      => ["- run: $pest \"$file\"", true],
        'after a value'      => ["- run: $pest --log-teamcity pest.log $file", true],
        'heredoc comment'    => ["- run: |\n    # cat <<EOF\n    $pest $file", true],
        'heredoc quoted'     => ["- run: |\n    echo 'cat <<EOF'\n    $pest $file", true],
        'valueless flag'     => ["- run: $pest --testdox-summary $file", true],
        'optional value'     => ["- run: $pest --coverage-text $file", true],
        'report destination' => ["- run: $pest --coverage-crap4j $file", false],
        'spaced value'       => ["- run: $pest --version --log-junit $file", false],
        'guard only'         => ["- if: steps.guard.outputs.run == 'true'\n  run: $pest $file", true],
        'guard not cancelled' => ["- if: \${{ !cancelled() && steps.guard.outputs.run == 'true' }}\n  run: $pest $file", true],
        'guard pgsql leg'    => ["- if: matrix.connection == 'pgsql' && steps.guard.outputs.run == 'true'\n  run: $pest $file", true],
        'guard mysql leg'    => ["- if: matrix.connection == 'mysql' && steps.guard.outputs.run == 'true'\n  run: $pest $file", false],
        'disabled'           => ["- if: false\n  run: $pest $file", false],
        'advisory leg'       => ["- if: \${{ matrix.advisory }}\n  run: $pest $file", false],
    ];

    $failures = 0;

    // The option list comes from PHPUnit by reflection; fail loudly if that source ever moves.
    if (! damGateTakesValue('--log-junit') || ! damGateTakesValue('--coverage-crap4j') || damGateTakesValue('--testdox-summary')) {
        $failures++;
        echo "::error::self-test: PHPUnit's option list could not be read\n";
    }

    foreach ($cases as $name => [$steps, $expected]) {
        $yaml = "jobs:\n  pest:\n    steps:\n".preg_replace('/^/m', '      ', $steps)."\n";
        $counted = isset(damGateNamed(Yaml::parse($yaml))[$file]);

        if ($counted !== $expected) {
            $failures++;
            echo "::error::self-test '$name': expected ".($expected ? 'counted' : 'not counted')."\n";
        }
    }

    echo ($failures ? "self-test failed: $failures case(s)" : 'self-test passed: '.count($cases).' cases')."\n";

    return $failures ? 1 : 0;
}

$arguments = array_slice($argv, 1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (($arguments[0] ?? null) === '--self-test') {
    exit(damGateSelfTest());
}

$root = rtrim($arguments[0] ?? dirname(__DIR__, 2), '/');
$named = damGateNamed(Yaml::parseFile($root.'/.github/workflows/pest_tests.yml'));

$all = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/Mothership/DAM/tests', FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if (str_ends_with($file->getFilename(), 'Test.php')) {
        $all[substr($file->getPathname(), strlen($root) + 1)] = true;
    }
}

ksort($all);
$unlisted = array_keys(array_diff_key($all, $named));
$missing = array_keys(array_diff_key($named, $all));

if ($unlisted) {
    echo "::error::DAM test files that no required-leg step in the pest job runs — add each to a step:\n".implode("\n", $unlisted)."\n";
    exit(1);
}

if ($missing) {
    echo "::error::the pest job names DAM test files that do not exist:\n".implode("\n", $missing)."\n";
    exit(1);
}

echo 'all '.count($all)." DAM test files are named in the pest job\n";
