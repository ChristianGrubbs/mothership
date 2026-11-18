<?php

namespace Mothership\AiAgent\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Mothership\AiAgent\Contracts\Credential as CredentialContract;
use Mothership\AiAgent\Presenters\CredentialPresenter;
use Mothership\HistoryControl\Interfaces\PresentableHistoryInterface;
use Mothership\HistoryControl\Traits\HistoryTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'label',
    'provider',
    'apiUrl',
    'apiKey',
    'model',
    'extras',
    'status',
])]
#[Hidden([
    'apiKey',
])]
#[Table(name: 'ai_agent_credentials')]
class Credential extends Model implements AuditableContract, CredentialContract, PresentableHistoryInterface
{
    use HasFactory, HistoryTrait;

    /**
     * Tags for history/audit.
     *
     * @var array
     */
    protected $historyTags = ['ai-agent-credential'];

    /**
     * Fields excluded from history audit — sensitive values.
     *
     * @var array
     */
    protected $auditExclude = [
        'apiKey',
    ];

    /**
     * History auditable attributes.
     *
     * @var array
     */
    protected $historyAuditable = [
        'label',
        'provider',
        'apiUrl',
        'model',
        'status',
    ];

    /**
     * {@inheritdoc}
     */
    public static function getPresenters(): array
    {
        return [
            'common' => CredentialPresenter::class,
        ];
    }

    protected static function booted(): void
    {
        // Two cascade paths reach ai_agent_executions from a credential (directly, and through its
        // agents), which SQL Server rejects, so there executions.credentialId is NO ACTION and is
        // cleared here instead. Every other driver sets it null on its own.
        static::deleting(function (Credential $credential): void {
            if ($credential->getConnection()->getDriverName() === 'sqlsrv') {
                AgentExecution::query()->where('credentialId', $credential->getKey())->update(['credentialId' => null]);
            }
        });
    }

    /**
     * Casts.
     */
    protected function casts(): array
    {
        return [
            'extras' => 'array',
            'status' => 'boolean',
            'apiKey' => 'encrypted',
        ];
    }
}
