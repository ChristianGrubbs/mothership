<?php

namespace Mothership\AdminApi\Services;

/**
 * Dispatched once per MCP client token request, after Passport answers. It names the grant so a lasting
 * connection (refresh grants) can be told apart from repeated consent (authorization-code grants). It carries
 * no code, token, callback or request parameter; the account id is set only when Passport issued a token.
 */
final class McpTokenExchanged
{
    /** Request attribute where the issued token's account id is kept for the middleware. */
    public const USER_ATTRIBUTE = '_mcpIssuedUser';

    public function __construct(
        public readonly string $grantType,
        public readonly string $provider,
        public readonly int $status,
        public readonly ?int $userId = null,
        public readonly ?string $error = null,
    ) {}

    public function issued(): bool
    {
        return $this->status === 200;
    }
}
