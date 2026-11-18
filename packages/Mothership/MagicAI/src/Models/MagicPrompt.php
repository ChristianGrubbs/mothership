<?php

namespace Mothership\MagicAI\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\MagicAI\Contracts\MagicPrompt as MagicPromptContract;
use Mothership\MagicAI\Database\Factories\MagicPromptFactory;

#[Fillable([
    'prompt',
    'title',
    'type',
    'purpose',
    'tone',
])]
#[Table(name: 'magic_ai_prompts')]
class MagicPrompt extends Model implements HistoryAuditable, MagicPromptContract
{
    use HasFactory;
    use HistoryTrait;

    /**
     * @var array<int, string>
     */
    protected array $historyTags = ['magicPrompt'];

    protected static function newFactory(): Factory
    {
        return MagicPromptFactory::new();
    }
}
