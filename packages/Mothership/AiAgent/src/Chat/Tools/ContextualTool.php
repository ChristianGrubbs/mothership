<?php

namespace Mothership\AiAgent\Chat\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mothership\AiAgent\Chat\ChatContext;
use Stringable;

abstract class ContextualTool implements Tool
{
    public function __construct(protected ChatContext $context) {}

    abstract public function name(): string;

    abstract public function description(): Stringable|string;

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    abstract public function handle(Request $request): Stringable|string;
}
