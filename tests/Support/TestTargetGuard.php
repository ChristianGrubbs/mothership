<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Raised when a test run would reach a database that is not the one disposable instance the lane was admitted for.
 */
final class TestTargetRefused extends RuntimeException {}

/**
 * Fail-closed admission of the database a guarded test lane may touch.
 *
 * Admits exactly one disposable, private PostgreSQL instance, named by an allowlist file that lives OUTSIDE the
 * repository (host, port, database, data directory, worktree). Process env, APP_ENV=testing, a database-name prefix or
 * a transaction rollback are never an admission: the allowlist is checked against the configuration Laravel actually
 * ended up with, against the postmaster's own pid file, and against the live server the connection reaches.
 *
 * Deliberately free of Laravel and of any database access of its own: callers hand it arrays and probes, so every
 * rejection is unit-testable without booting the application. Output is limited to the allowlisted identity fields.
 */
final class TestTargetGuard
{
    /** Set to "1" by the guarded phpunit config; with it present, a missing or invalid allowlist is fatal. */
    public const REQUIRE_ENV = 'MOTHERSHIP_REQUIRE_TARGET_GUARD';

    /** Absolute path of the allowlist JSON file (outside the repository). */
    public const ALLOWLIST_ENV = 'MOTHERSHIP_TEST_TARGET_ALLOWLIST';

    /** Defined once a guarded lane starts; the installer refuses to run when it is defined. */
    public const LANE_CONSTANT = 'MOTHERSHIP_GUARDED_TEST_LANE';

    /** Hex SHA-256 of the independently reviewed, frozen allowlist file. */
    public const ALLOWLIST_SHA256_ENV = 'MOTHERSHIP_TEST_TARGET_ALLOWLIST_SHA256';

    public const CONNECTION = 'pgsql';

    public const LIVE_QUERY = "select current_database() as database, host(inet_server_addr()) as host, inet_server_port() as port, current_setting('data_directory') as data_directory, floor(extract(epoch from pg_postmaster_start_time()))::bigint as started_at";

    private const ALLOWLIST_KEYS = ['host', 'port', 'database', 'data_directory', 'worktree'];

    /** Absolute path of the CI service proof (private, under RUNNER_TEMP) and its separately passed hex SHA-256. */
    public const CI_PROOF_ENV = 'MOTHERSHIP_CI_PROOF';

    public const CI_PROOF_SHA256_ENV = 'MOTHERSHIP_CI_PROOF_SHA256';

    /** The job's own service container id and Docker-allocated host port, from the Actions `job.services` context. */
    public const CI_SERVICE_ID_ENV = 'MOTHERSHIP_CI_SERVICE_ID';

    public const CI_SERVICE_PORT_ENV = 'MOTHERSHIP_CI_SERVICE_PORT';

    public const CI_DATABASE = 'mothership_test_ci';

    public const CI_DATA_DIRECTORY = '/var/lib/postgresql/data';

    public const CI_CONTAINER_PORT = 5432;

    private const CI_PROOF_KEYS = ['container_id', 'run_id', 'run_attempt', 'workspace', 'host_port', 'container_ips', 'database', 'data_directory', 'tmpfs', 'started_at', 'system_identifier'];

    public static function required(): bool
    {
        return getenv(self::REQUIRE_ENV) === '1' || (string) getenv(self::ALLOWLIST_ENV) !== '' || (string) getenv(self::CI_PROOF_ENV) !== '';
    }

    /** Class constant a test class sets to true to demand an admitted lane whenever its effective driver is PostgreSQL. */
    public const DEMAND_CONSTANT = 'DEMANDS_GUARDED_LANE';

    /**
     * For a class that demands admission while no guard mode was selected at all (no allowlist, no proof, no require flag):
     * runs on the effective configuration right after it loads, before any provider or connector exists, and opens nothing.
     * Only a default connection that is provably a non-PostgreSQL driver may continue (those runs skip as before); a
     * PostgreSQL, unknown, url-driven or missing default is refused.
     *
     * @param  array<string, mixed>  $database  config('database')
     */
    public static function refuseUnadmittedPostgres(array $database): void
    {
        $name = $database['default'] ?? null;
        $connection = is_string($name) ? ($database['connections'][$name] ?? null) : null;
        $driver = is_array($connection) ? ($connection['driver'] ?? null) : null;
        $url = is_array($connection) ? ($connection['url'] ?? null) : null;

        if (in_array($driver, ['sqlite', 'mysql', 'mariadb', 'sqlsrv'], true) && ($url === null || $url === '')) {
            return;
        }

        throw new TestTargetRefused('Guarded test lane: this test demands an admitted PostgreSQL target, but no guard mode (local allowlist or CI proof) was supplied; refusing before providers boot.');
    }

    /** The CI admission mode is selected by the presence of a proof, never by ambient CI variables; both modes at once is ambiguous. */
    public static function ciMode(): bool
    {
        $proof = (string) getenv(self::CI_PROOF_ENV) !== '';

        if ($proof && (string) getenv(self::ALLOWLIST_ENV) !== '') {
            throw new TestTargetRefused('Guarded test lane: a local allowlist and a CI proof were both supplied; refusing to choose.');
        }

        return $proof;
    }

    /**
     * Environment the CI admission reads, collected in one place so tests can hand it in as an array.
     *
     * @return array<string, string>
     */
    public static function ciEnvironment(): array
    {
        $out = [];

        foreach ([self::CI_PROOF_ENV, self::CI_PROOF_SHA256_ENV, self::CI_SERVICE_ID_ENV, self::CI_SERVICE_PORT_ENV, 'GITHUB_ACTIONS', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'GITHUB_WORKSPACE', 'RUNNER_TEMP'] as $key) {
            $value = getenv($key);
            $out[$key] = $value === false ? '' : $value;
        }

        return $out;
    }

    /**
     * Admission target for the required CI leg, proved from the job's own service container instead of a local allowlist.
     *
     * The workflow step writes a private proof (container id, run id/attempt, workspace, Docker-allocated port, container
     * address, tmpfs data directory, postmaster start time, system identifier) from `docker inspect` and a read-only psql
     * inside that container, and hands the proof's SHA-256 over separately. Nothing here trusts a caller-set target string,
     * GITHUB_ACTIONS on its own, a database-name prefix or a path prefix: every part must agree with the runner's own
     * environment, and the live server must later report the identity the proof recorded.
     *
     * The result has the allowlist shape (so every local check is reused) plus a `ci` entry the live check reads.
     *
     * @param  array<string, string>  $env  see ciEnvironment()
     * @return array{host: string, port: int, database: string, data_directory: string, worktree: string, ci: array{container_ips: list<string>, container_port: int, started_at: int, system_identifier: string}}
     */
    public static function loadCiProof(array $env, string $basePath): array
    {
        $get = static fn (string $key): string => is_string($env[$key] ?? null) ? $env[$key] : '';

        if ($get('GITHUB_ACTIONS') !== 'true' || ! preg_match('/^[0-9]{1,20}$/D', $get('GITHUB_RUN_ID')) || ! preg_match('/^[0-9]{1,6}$/D', $get('GITHUB_RUN_ATTEMPT')) || $get('GITHUB_WORKSPACE') === '' || $get('RUNNER_TEMP') === '') {
            throw new TestTargetRefused('Guarded test lane: a CI proof is only accepted inside a GitHub Actions job (runner variables missing).');
        }

        $path = $get(self::CI_PROOF_ENV);
        $real = $path === '' ? false : realpath($path);

        if ($real === false || ! is_file($real) || is_link($path) || $real !== $path) {
            throw new TestTargetRefused('Guarded test lane: the CI proof is missing or not addressed by its canonical, non-link path.');
        }

        $temp = realpath($get('RUNNER_TEMP'));
        $base = realpath($basePath);

        if ($temp === false || ! str_starts_with($real, $temp.DIRECTORY_SEPARATOR) || ($base !== false && str_starts_with($real, $base.DIRECTORY_SEPARATOR))) {
            throw new TestTargetRefused('Guarded test lane: the CI proof must live under RUNNER_TEMP, outside the checkout.');
        }

        $stat = stat($real);

        if ($stat === false || ! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0o077) !== 0) {
            throw new TestTargetRefused('Guarded test lane: the CI proof must be owned by the current user and closed to group and others.');
        }

        $bytes = (string) file_get_contents($real);

        if (! preg_match('/^[0-9a-f]{64}$/D', $get(self::CI_PROOF_SHA256_ENV)) || ! hash_equals($get(self::CI_PROOF_SHA256_ENV), hash('sha256', $bytes))) {
            throw new TestTargetRefused('Guarded test lane: the CI proof does not match its separately passed hash.');
        }

        try {
            $proof = json_decode($bytes, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TestTargetRefused('Guarded test lane: the CI proof is not valid JSON.');
        }

        $keys = is_array($proof) ? array_keys($proof) : [];
        sort($keys);
        $expected = self::CI_PROOF_KEYS;
        sort($expected);

        if ($keys !== $expected) {
            throw new TestTargetRefused('Guarded test lane: the CI proof does not hold exactly the expected fields.');
        }

        $workspace = realpath($get('GITHUB_WORKSPACE'));

        $consistent = is_string($proof['container_id']) && preg_match('/^[0-9a-f]{64}$/D', $proof['container_id']) === 1
            && $proof['container_id'] === $get(self::CI_SERVICE_ID_ENV)
            && $proof['run_id'] === $get('GITHUB_RUN_ID')
            && $proof['run_attempt'] === $get('GITHUB_RUN_ATTEMPT')
            && $workspace !== false && $workspace === $get('GITHUB_WORKSPACE')
            && $proof['workspace'] === $workspace
            && $base !== false && $base === $workspace
            && is_int($proof['host_port']) && $proof['host_port'] >= 1024 && $proof['host_port'] <= 65535 && ! in_array($proof['host_port'], [5432, 5433], true)
            && (string) $proof['host_port'] === $get(self::CI_SERVICE_PORT_ENV)
            && self::ipList($proof['container_ips'])
            && $proof['database'] === self::CI_DATABASE
            && $proof['data_directory'] === self::CI_DATA_DIRECTORY
            && $proof['tmpfs'] === true
            && is_int($proof['started_at']) && $proof['started_at'] > 0
            && is_string($proof['system_identifier']) && preg_match('/^[0-9]{1,20}$/D', $proof['system_identifier']) === 1;

        if (! $consistent) {
            throw new TestTargetRefused('Guarded test lane: the CI proof is not consistent with this job (container, run, attempt, workspace, port, database or tmpfs).');
        }

        return [
            'host'           => '127.0.0.1',
            'port'           => $proof['host_port'],
            'database'       => self::CI_DATABASE,
            'data_directory' => self::CI_DATA_DIRECTORY,
            'worktree'       => $workspace,
            'ci'             => [
                'container_ips'     => $proof['container_ips'],
                'container_port'    => self::CI_CONTAINER_PORT,
                'started_at'        => $proof['started_at'],
                'system_identifier' => $proof['system_identifier'],
            ],
        ];
    }

    /** One to eight distinct IPv4 addresses as a list: every address the service container holds on its networks. */
    private static function ipList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) >= 1 && count($value) <= 8 && count(array_unique($value)) === count($value)
            && array_filter($value, static fn ($ip) => ! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) === [];
    }

    /**
     * The identity query for the admitted target: the CI service additionally reports its system identifier.
     *
     * @param  array<string, mixed>  $allowlist
     */
    public static function liveQuery(array $allowlist): string
    {
        return isset($allowlist['ci'])
            ? self::LIVE_QUERY.', (select system_identifier::text from pg_control_system()) as system_identifier'
            : self::LIVE_QUERY;
    }

    /**
     * @return array{host: string, port: int, database: string, data_directory: string, worktree: string}
     */
    public static function loadAllowlist(string $path, string $basePath, string $expectedSha256 = ''): array
    {
        $real = $path === '' ? false : realpath($path);

        if ($real === false || ! is_file($real)) {
            throw new TestTargetRefused('Guarded test lane: allowlist file is missing; refusing to run.');
        }

        // A link, a non-canonical path or a file someone else owns or can write is not a reviewed allowlist.
        if (is_link($path) || $real !== $path) {
            throw new TestTargetRefused('Guarded test lane: the allowlist must be addressed by its canonical path, not a link.');
        }

        $stat = stat($real);

        if ($stat === false || ! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0o077) !== 0) {
            throw new TestTargetRefused('Guarded test lane: the allowlist must be owned by the current user and closed to group and others.');
        }

        $base = realpath($basePath);

        if ($base !== false && str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
            throw new TestTargetRefused('Guarded test lane: the allowlist must live outside the repository.');
        }

        $bytes = (string) file_get_contents($real);

        if (! preg_match('/^[0-9a-f]{64}$/D', $expectedSha256) || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new TestTargetRefused('Guarded test lane: the allowlist does not match the reviewed, frozen hash.');
        }

        try {
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TestTargetRefused('Guarded test lane: allowlist is not valid JSON.');
        }

        $keys = is_array($data) ? array_keys($data) : [];
        sort($keys);
        $expected = self::ALLOWLIST_KEYS;
        sort($expected);

        if ($keys !== $expected) {
            throw new TestTargetRefused('Guarded test lane: the allowlist must hold exactly host, port, database, data_directory and worktree.');
        }

        if (is_string($data['database'] ?? null) && ! preg_match('/^[A-Za-z0-9_]{1,63}$/D', $data['database'])) {
            throw new TestTargetRefused('Guarded test lane: the allowlisted database name must be plain identifier characters.');
        }

        foreach (['host', 'database', 'data_directory', 'worktree'] as $key) {
            if (! is_string($data[$key]) || $data[$key] === '') {
                throw new TestTargetRefused("Guarded test lane: allowlist {$key} must be a non-empty string.");
            }
        }

        if (! is_int($data['port']) || $data['port'] < 1 || $data['port'] > 65535) {
            throw new TestTargetRefused('Guarded test lane: allowlist port must be an integer.');
        }

        if (! in_array($data['host'], ['127.0.0.1', '::1'], true)) {
            throw new TestTargetRefused('Guarded test lane: only a loopback host can be a private disposable target.');
        }

        foreach (['data_directory', 'worktree'] as $key) {
            if (realpath($data[$key]) === false) {
                throw new TestTargetRefused("Guarded test lane: allowlist {$key} does not exist.");
            }
        }

        return [
            'host'           => $data['host'],
            'port'           => $data['port'],
            'database'       => $data['database'],
            'data_directory' => realpath($data['data_directory']),
            'worktree'       => realpath($data['worktree']),
        ];
    }

    /**
     * Checks the effective `database` configuration (as Laravel loaded it) names exactly the allowlisted target.
     *
     * @param  array<string, mixed>  $database  config('database')
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     */
    public static function assertConfig(array $database, array $allowlist, string $basePath): void
    {
        if (($database['default'] ?? null) !== self::CONNECTION) {
            throw new TestTargetRefused('Guarded test lane: the default connection is not the admitted PostgreSQL connection.');
        }

        $connection = $database['connections'][self::CONNECTION] ?? null;

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
            throw new TestTargetRefused('Guarded test lane: the admitted connection is missing or is not PostgreSQL.');
        }

        self::assertSafeOptions($connection);

        foreach (['read', 'write', 'sticky'] as $key) {
            if (array_key_exists($key, $connection)) {
                throw new TestTargetRefused("Guarded test lane: connection {$key} overrides escape admission.");
            }
        }

        // PostgresConnector::getDsn uses `??`, so a non-null connect_via_* (an empty string included) replaces the nominal
        // database or port; only null is inert. Laravel ignores an empty url, and pgsql has no unix_socket.
        foreach (['connect_via_database', 'connect_via_port'] as $key) {
            if (array_key_exists($key, $connection) && $connection[$key] !== null) {
                throw new TestTargetRefused("Guarded test lane: connection {$key} overrides escape admission.");
            }
        }

        foreach (['url', 'unix_socket'] as $key) {
            if (isset($connection[$key]) && $connection[$key] !== '') {
                throw new TestTargetRefused("Guarded test lane: connection {$key} overrides escape admission.");
            }
        }

        if (! is_string($connection['host'] ?? null) || $connection['host'] !== $allowlist['host']) {
            throw new TestTargetRefused('Guarded test lane: connection host is not the admitted host.');
        }

        if (! (is_int($connection['port'] ?? null) || is_string($connection['port'] ?? null)) || (string) $connection['port'] !== (string) $allowlist['port']) {
            throw new TestTargetRefused('Guarded test lane: connection port is not the admitted port.');
        }

        if (! is_string($connection['database'] ?? null) || $connection['database'] !== $allowlist['database']) {
            throw new TestTargetRefused('Guarded test lane: connection database is not the admitted database.');
        }

        $base = realpath($basePath);

        if ($base === false || $base !== $allowlist['worktree']) {
            throw new TestTargetRefused('Guarded test lane: this is not the admitted worktree.');
        }
    }

    /** Keys that session initialisation turns into SQL after the connection opens. Never run before identity is proved. */
    public const SESSION_KEYS = ['isolation_level', 'timezone', 'search_path', 'schema', 'synchronous_commit'];

    private const OPTION_RULES = [
        // Enter the DSN verbatim (PostgresConnector::getDsn / addSslOptions): explicit words only, never free text.
        'charset'          => ['/^(utf8|UTF8)$/D'],
        'sslmode'          => ['/^(disable|allow|prefer|require|verify-ca|verify-full)$/D'],
        'application_name' => ['/^[A-Za-z0-9_.-]{1,63}$/D'],
        // Become SQL after the connection opens.
        'search_path'        => ['/^[A-Za-z0-9_]+(, ?[A-Za-z0-9_]+)*$/D'],
        'schema'             => ['/^[A-Za-z0-9_]+(, ?[A-Za-z0-9_]+)*$/D'],
        'timezone'           => ['/^[A-Za-z0-9_\/+:-]{1,64}$/D'],
        'isolation_level'    => ['/^(read committed|repeatable read|serializable|read uncommitted)$/iD'],
        'synchronous_commit' => ['/^(on|off|local|remote_write|remote_apply)$/D'],
        // Not part of the DSN, but still scalars.
        'username'         => ['/^[\x20-\x7e]{0,128}$/D'],
        'password'         => ['/^[\x20-\x7e]{0,256}$/D'],
        'prefix'           => ['/^[A-Za-z0-9_]{0,32}$/D'],
        'name'             => ['/^[A-Za-z0-9_.-]{1,64}$/D'],
    ];

    /** Options that are unsupported in a guarded lane: they must be absent or null. */
    private const FORBIDDEN_OPTIONS = ['sslcert', 'sslkey', 'sslrootcert', 'options', 'sticky_read'];

    private const OTHER_ALLOWED_KEYS = ['driver', 'host', 'port', 'database', 'prefix_indexes', 'url', 'unix_socket', 'read', 'write', 'sticky', 'connect_via_database', 'connect_via_port'];

    /**
     * Every value that reaches the DSN, or becomes SQL on the server, must be an explicit normal value; anything else
     * (delimiters, quotes, control characters, unknown keys, certificate paths, PDO options) is refused before any
     * probe, liveness call or connector. The admitted host, port and database are compared exactly elsewhere.
     *
     * @param  array<string, mixed>  $config
     */
    public static function assertSafeOptions(array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (! is_string($key) || ! (isset(self::OPTION_RULES[$key]) || in_array($key, self::FORBIDDEN_OPTIONS, true) || in_array($key, self::OTHER_ALLOWED_KEYS, true))) {
                throw new TestTargetRefused('Guarded test lane: the connection carries an option the guarded lane does not support.');
            }
        }

        foreach (self::FORBIDDEN_OPTIONS as $key) {
            if (array_key_exists($key, $config) && $config[$key] !== null && $config[$key] !== [] && $config[$key] !== '') {
                throw new TestTargetRefused("Guarded test lane: connection option {$key} is not supported.");
            }
        }

        foreach (self::OPTION_RULES as $key => [$pattern]) {
            if (! array_key_exists($key, $config) || $config[$key] === null) {
                continue;
            }

            if (! is_string($config[$key]) || ! preg_match($pattern, $config[$key])) {
                throw new TestTargetRefused("Guarded test lane: connection option {$key} is not an explicit normal value.");
            }
        }

        if (isset($config['prefix_indexes']) && ! is_bool($config['prefix_indexes'])) {
            throw new TestTargetRefused('Guarded test lane: connection option prefix_indexes must be a boolean.');
        }
    }

    /**
     * The configuration a connector is about to open a connection with (after read/write merging), checked before any
     * PDO exists. Stricter than assertConfig: PostgresConnector::getDsn substitutes connect_via_database and
     * connect_via_port for database and port, so those keys cannot be allowed to differ from what was checked.
     *
     * @param  array<string, mixed>  $config
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     */
    public static function assertConnectorConfig(array $config, array $allowlist): void
    {
        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new TestTargetRefused('Guarded test lane: only the admitted PostgreSQL driver may open a connection.');
        }

        self::assertSafeOptions($config);

        foreach (['connect_via_database', 'connect_via_port'] as $key) {
            if (array_key_exists($key, $config) && $config[$key] !== null) {
                throw new TestTargetRefused("Guarded test lane: connection {$key} overrides escape admission.");
            }
        }

        foreach (['read', 'write', 'url', 'unix_socket'] as $key) {
            if (array_key_exists($key, $config) && $config[$key] !== null && $config[$key] !== '') {
                throw new TestTargetRefused("Guarded test lane: connection {$key} overrides escape admission.");
            }
        }

        if (($config['host'] ?? null) !== $allowlist['host']
            || ! (is_int($config['port'] ?? null) || is_string($config['port'] ?? null))
            || (string) $config['port'] !== (string) $allowlist['port']
            || ($config['database'] ?? null) !== $allowlist['database']) {
            throw new TestTargetRefused('Guarded test lane: the connection is not the admitted target.');
        }
    }

    /**
     * Everything that can be proved before a PDO exists: the connector-effective configuration and the running
     * postmaster. Returns the postmaster identity for the live check that follows the connection.
     *
     * @param  array<string, mixed>  $config
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     * @return array{data_directory: string, pid: int, port: int, started_at: int}
     */
    public static function assertBeforeConnect(array $config, array $allowlist, ?callable $alive = null): array
    {
        self::assertConnectorConfig($config, $allowlist);

        return self::assertPostmaster($allowlist, $alive);
    }

    /**
     * Reads the postmaster's own pid file (no database connection) and checks it belongs to the admitted instance.
     *
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     * @param  callable(int): bool|null  $alive  defaults to posix_kill($pid, 0)
     * @return array{data_directory: string, pid: int, port: int, started_at: int}
     */
    public static function assertPostmaster(array $allowlist, ?callable $alive = null): array
    {
        // The CI service runs in a container: its postmaster identity is the one the proof recorded, checked again live.
        if (isset($allowlist['ci'])) {
            return ['data_directory' => $allowlist['data_directory'], 'pid' => 0, 'port' => $allowlist['ci']['container_port'], 'started_at' => $allowlist['ci']['started_at']];
        }

        $file = $allowlist['data_directory'].DIRECTORY_SEPARATOR.'postmaster.pid';
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;

        if ($lines === false || count($lines) < 8) {
            throw new TestTargetRefused('Guarded test lane: the admitted data directory has no running postmaster.');
        }

        [$pid, $dir, $started, $port, , $listen] = array_map('trim', array_slice($lines, 0, 6));
        $status = trim($lines[7]);

        if (realpath($dir) !== $allowlist['data_directory']) {
            throw new TestTargetRefused('Guarded test lane: the postmaster runs from a different data directory.');
        }

        if ((string) $allowlist['port'] !== $port) {
            throw new TestTargetRefused('Guarded test lane: the postmaster listens on a different port.');
        }

        if ($listen !== $allowlist['host']) {
            throw new TestTargetRefused('Guarded test lane: the postmaster listens beyond the admitted private address.');
        }

        if ($status !== 'ready') {
            throw new TestTargetRefused('Guarded test lane: the postmaster is not ready.');
        }

        if (! ctype_digit($pid) || ! ctype_digit($started)) {
            throw new TestTargetRefused('Guarded test lane: the postmaster pid file is malformed.');
        }

        $alive ??= static fn (int $pid): bool => function_exists('posix_kill') && posix_kill($pid, 0);

        if (! $alive((int) $pid)) {
            throw new TestTargetRefused('Guarded test lane: the postmaster process is not running.');
        }

        return ['data_directory' => $allowlist['data_directory'], 'pid' => (int) $pid, 'port' => (int) $port, 'started_at' => (int) $started];
    }

    /**
     * Checks the server a connection really reached (the row LIVE_QUERY returns) against the allowlist and the pid file.
     *
     * @param  array<string, mixed>  $row
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     * @param  array{data_directory: string, pid: int, port: int, started_at: int}  $postmaster
     */
    public static function assertLive(array $row, array $allowlist, array $postmaster): void
    {
        if (isset($allowlist['ci'])) {
            // Through Docker's port mapping the server sees its own container address and port, and its data directory is a
            // path inside the container (no realpath on the runner).
            $ci = $allowlist['ci'];

            if (($row['database'] ?? null) !== $allowlist['database']
                || ! in_array($row['host'] ?? null, $ci['container_ips'], true)
                || (int) ($row['port'] ?? 0) !== $ci['container_port']
                || ($row['data_directory'] ?? null) !== $allowlist['data_directory']
                || (int) ($row['started_at'] ?? -1) !== $postmaster['started_at']
                || ($row['system_identifier'] ?? null) !== $ci['system_identifier']) {
                throw new TestTargetRefused('Guarded test lane: the live server is not the CI service the proof describes.');
            }

            return;
        }

        $dataDirectory = is_string($row['data_directory'] ?? null) ? realpath($row['data_directory']) : false;

        $matches = ($row['database'] ?? null) === $allowlist['database']
            && ($row['host'] ?? null) === $allowlist['host']
            && (int) ($row['port'] ?? 0) === $allowlist['port']
            && $dataDirectory === $allowlist['data_directory']
            && (int) ($row['started_at'] ?? -1) === $postmaster['started_at'];

        if (! $matches) {
            throw new TestTargetRefused('Guarded test lane: the live server is not the admitted instance.');
        }
    }

    /**
     * Full admission from the effective configuration, before anything else touches the database.
     *
     * @param  array<string, mixed>  $database
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     * @param  callable(array<string, mixed>): array<string, mixed>  $probe  returns the LIVE_QUERY row for the given connection config
     * @return array{host: string, port: int, database: string, data_directory: string, postmaster_pid: int}
     */
    public static function admit(array $database, array $allowlist, string $basePath, callable $probe, ?callable $alive = null): array
    {
        self::assertConfig($database, $allowlist, $basePath);
        $postmaster = self::assertPostmaster($allowlist, $alive);
        self::assertLive($probe($database['connections'][self::CONNECTION]), $allowlist, $postmaster);

        return self::identity($allowlist, $postmaster);
    }

    /**
     * Identity fields only; nothing here can carry a credential.
     *
     * @return array{host: string, port: int, database: string, data_directory: string, postmaster_pid: int}
     */
    public static function identity(array $allowlist, array $postmaster): array
    {
        return [
            'host'           => $allowlist['host'],
            'port'           => $allowlist['port'],
            'database'       => $allowlist['database'],
            'data_directory' => $allowlist['data_directory'],
            'postmaster_pid' => $postmaster['pid'],
        ];
    }

    public static function markLane(): void
    {
        if (! defined(self::LANE_CONSTANT)) {
            define(self::LANE_CONSTANT, true);
        }
    }
}
