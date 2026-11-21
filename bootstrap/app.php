<?php

use App\Http\Middleware\EnsureHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [EnsureHttps::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Kata sandi tidak pernah dikembalikan ke formulir lewat session saat validasi gagal.
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'current_password',
            'options.password',
            'options.password_confirmation',
            'options.owner_password',
            'options.steps',
        ]);

        // Unggahan yang melebihi batas server ditolak sebelum disimpan (MSG-02).
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('konvertin.msg02')], 413);
            }

            return back()->withErrors(['files' => __('konvertin.msg02')]);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('konvertin.too_many_requests')], 429, $exception->getHeaders());
            }

            return null;
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('konvertin.page_expired')], 419);
            }

            return null;
        });
    })->create();
