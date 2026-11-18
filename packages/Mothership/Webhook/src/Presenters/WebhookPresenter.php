<?php

namespace Mothership\Webhook\Presenters;

use Mothership\HistoryControl\Interfaces\HistoryPresenterInterface;

/**
 * A webhook's custom headers often carry the receiver's credential (an `Authorization` header). History names the
 * headers that changed and never shows a value: the audit stores them masked (Webhook::transformAudit), and rows
 * written before that are masked here as they are read.
 */
class WebhookPresenter implements HistoryPresenterInterface
{
    public const MASK = '••••••••';

    /**
     * {@inheritdoc}
     */
    public static function representValueForHistory(mixed $oldValues, mixed $newValues, string $fieldName): array
    {
        $old = static::headers($oldValues);
        $new = static::headers($newValues);

        // An empty result would hand the raw column to the history controller's generic display, so a change with
        // nothing to name still answers with an entry, which the controller drops for having no values.
        $normalizedData = ['headers' => ['name' => 'Headers', 'old' => null, 'new' => null]];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $name) {
            $inOld = array_key_exists($name, $old);
            $inNew = array_key_exists($name, $new);

            // A masked value present on both sides is a change: the audit keeps only the headers that changed.
            if ($inOld && $inNew && $old[$name] === $new[$name] && $old[$name] !== static::MASK) {
                continue;
            }

            $label = 'Header: '.$name;

            $normalizedData[$label] = [
                'name' => $label,
                'old'  => $inOld ? static::MASK : null,
                'new'  => $inNew ? static::MASK : null,
            ];
        }

        return $normalizedData;
    }

    /**
     * The headers of one side of a change, keyed by header name, with only the values that changed, masked.
     *
     * @param  array<string, mixed>  $own
     * @param  array<string, mixed>  $other
     * @return array<string, string>
     */
    public static function maskChanged(array $own, array $other): array
    {
        $masked = [];

        foreach ($own as $name => $value) {
            if (! array_key_exists($name, $other) || $other[$name] !== $value) {
                $masked[$name] = static::MASK;
            }
        }

        return $masked;
    }

    /**
     * An audited `headers` value: the raw JSON column, or the array a masked audit stores.
     *
     * @return array<string, mixed>
     */
    public static function headers(mixed $value): array
    {
        $headers = is_string($value) ? json_decode($value, true) : $value;

        return is_array($headers) ? $headers : [];
    }
}
