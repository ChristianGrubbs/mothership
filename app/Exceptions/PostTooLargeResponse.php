<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostTooLargeResponse
{
    public function __invoke(PostTooLargeException $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'success'   => false,
            'code'      => 'request_too_large',
            'message'   => 'The request body exceeds the server upload limit.',
            'retryable' => false,
            'upload'    => null,
        ], 413);
    }
}
