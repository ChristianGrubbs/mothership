<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/**
 * Reads and enforces Config/dam_events.php (§3.6.3). An unknown name, an unknown prop or a prop of the wrong type
 * rejects the whole event: nothing is silently trimmed into something it was not.
 */
final class EventRegistry
{
    public const OUTCOME_ATTEMPT = 'attempt';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    public const OUTCOME_ABANDON = 'abandon';

    public const OUTCOME_NONE = 'none';

    public const OUTCOMES = [self::OUTCOME_ATTEMPT, self::OUTCOME_SUCCESS, self::OUTCOME_FAILURE, self::OUTCOME_ABANDON, self::OUTCOME_NONE];

    public const SCOPE_ADMIN = 'admin';

    public const SCOPE_SHARE = 'share';

    private const SHORT = '/\A[A-Za-z0-9_.:-]{1,48}\z/';

    private const TOKEN = '/\A[A-Za-z0-9-]{8,64}\z/';

    private const MAX_INT = 1_000_000_000;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return (array) config('dam_events.events', []);
    }

    public static function exists(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /** @return array<string, mixed> */
    public static function definition(string $name): array
    {
        $definition = self::all()[$name] ?? null;

        if (! is_array($definition)) {
            throw new \InvalidArgumentException("Unregistered DAM event {$name}.");
        }

        return $definition;
    }

    /**
     * The client allow-list a page renders for the collector: name => version, for the events this scope may send.
     *
     * @return array<string, int>
     */
    public static function clientCatalogue(string $scope): array
    {
        $catalogue = [];

        foreach (self::all() as $name => $definition) {
            if (($definition['emitter'] ?? null) === 'client' && in_array($scope, (array) ($definition['scopes'] ?? []), true)) {
                $catalogue[$name] = (int) $definition['v'];
            }
        }

        return $catalogue;
    }

    /**
     * The props as stored, or null when any key is not declared or any value is not its declared type. A `term`
     * prop is returned apart, as the second element, and never stays on the event (§3.6.7).
     *
     * @param  array<string, mixed>  $props
     * @return array{0: array<string, int|bool|string>, 1: ?string}|null
     */
    public static function validateProps(string $name, array $props): ?array
    {
        $declared = (array) (self::definition($name)['props'] ?? []);
        $kept = [];
        $term = null;

        foreach ($props as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $declared)) {
                return null;
            }

            $type = $declared[$key];

            if ($type === 'term') {
                if (! is_string($value) || mb_strlen($value) > 100) {
                    return null;
                }

                $term = $value;

                continue;
            }

            $valid = match (true) {
                is_array($type)    => is_string($value) && in_array($value, $type, true),
                $type === 'int'    => is_int($value) && $value >= 0 && $value <= self::MAX_INT,
                $type === 'bool'   => is_bool($value),
                $type === 'short'  => is_string($value) && preg_match(self::SHORT, $value) === 1,
                $type === 'token'  => is_string($value) && preg_match(self::TOKEN, $value) === 1,
                $type === 'bundle' => is_string($value) && preg_match('/\A[A-Za-z0-9_.-]{1,32}-[A-Za-z0-9_-]{8}\.js\z/', $value) === 1,
                default            => false,
            };

            if (! $valid) {
                return null;
            }

            $kept[$key] = $value;
        }

        return [$kept, $term];
    }

    public static function window(string $name): ?int
    {
        $window = self::definition($name)['window'] ?? null;

        return is_int($window) ? $window : null;
    }

    public static function terminalKey(string $name): ?string
    {
        $key = self::definition($name)['terminal'] ?? null;

        return is_string($key) ? $key : null;
    }
}
