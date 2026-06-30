<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Middleware CORS bawaan Laravel 11 — wajib supaya frontend Vercel
        // (domain berbeda) bisa fetch ke API ini tanpa diblok browser.
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // PENTING: project ini API-only, tidak ada route bernama 'login'.
        // Tanpa override ini, middleware auth bawaan Laravel (dipakai oleh
        // 'auth:customer' dan guard JWT lainnya) akan mencoba REDIRECT ke
        // route 'login' saat token tidak ada/tidak valid — dan karena
        // route itu tidak ada, malah melempar RouteNotFoundException
        // (bukan response 401 JSON yang seharusnya). Override ini
        // memaksa semua request ke /api/* selalu mendapat balasan JSON,
        // terlepas dari header Accept yang dikirim client.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();