<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

it('returns the stable chunk-upload error shape when PHP rejects a JSON upload body', function () {
    $request = Request::create('/admin/dam/assets/upload/chunks', 'POST');
    $request->headers->set('Accept', 'application/json');

    $response = app(ExceptionHandler::class)->render($request, new PostTooLargeException);

    expect($response->getStatusCode())->toBe(413)
        ->and(json_decode($response->getContent(), true))->toMatchArray([
            'success'   => false,
            'code'      => 'request_too_large',
            'retryable' => false,
        ]);
});
