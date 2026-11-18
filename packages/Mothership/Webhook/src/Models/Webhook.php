<?php

namespace Mothership\Webhook\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Interfaces\PresentableHistoryInterface;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\Webhook\Presenters\WebhookPresenter;

#[Fillable([
    'name',
    'url',
    'is_active',
    'events',
    'secret',
    'headers',
    'extra',
    'owner_id',
])]
#[Table(name: 'webhooks')]
class Webhook extends Model implements HistoryAuditable, PresentableHistoryInterface
{
    use HistoryTrait {
        transformAudit as historyTransformAudit;
    }

    protected $auditExclude = ['secret'];

    protected $historyTags = ['webhooks'];

    public $timestamps = true;

    public static function getPresenters(): array
    {
        return [
            'headers' => WebhookPresenter::class,
        ];
    }

    /**
     * Header values often carry the receiver's credential, so the audit keeps only which headers changed, masked,
     * as CoreConfig does for secret settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        $data = $this->historyTransformAudit($data);

        $old = WebhookPresenter::headers($data['old_values']['headers'] ?? null);
        $new = WebhookPresenter::headers($data['new_values']['headers'] ?? null);

        if (array_key_exists('headers', $data['old_values'] ?? [])) {
            $data['old_values']['headers'] = WebhookPresenter::maskChanged(own: $old, other: $new);
        }

        if (array_key_exists('headers', $data['new_values'] ?? [])) {
            $data['new_values']['headers'] = WebhookPresenter::maskChanged(own: $new, other: $old);
        }

        return $data;
    }

    public function getPrimaryModelIdForHistory(): int
    {
        return $this->id;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'events'    => 'array',
            'headers'   => 'array',
            'extra'     => 'array',
            'owner_id'  => 'integer',
        ];
    }

    /**
     * Whether this webhook is subscribed to the given event key.
     *
     * Read through getAttribute(): a bare $this->events matches Rector's
     * Laravel rename of the legacy Model::$events property and gets rewritten
     * to $dispatchesEvents, which silently empties the subscription list.
     */
    public function subscribesTo(string $event): bool
    {
        return in_array($event, (array) $this->getAttribute('events'), true);
    }
}
