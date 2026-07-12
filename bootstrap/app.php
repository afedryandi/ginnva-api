<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust semua proxy — wajib saat deploy di balik nginx/load balancer
        // agar IP-based rate limiting membaca IP client asli, bukan IP proxy.
        $middleware->prepend(\Illuminate\Http\Middleware\TrustProxies::class);
        // Middleware CORS bawaan Laravel 11 — wajib supaya frontend Vercel
        // (domain berbeda) bisa fetch ke API ini tanpa diblok browser.
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Kirim semua exception yang lolos ke Sentry (error tracking).
        // No-op otomatis kalau SENTRY_LARAVEL_DSN kosong di .env.
        Integration::handles($exceptions);

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
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        // Format SEMUA error validasi dari endpoint /api/* jadi bentuk
        // konsisten { success: false, message, errors }. Sebelum ini,
        // beberapa controller (mis. warranty/claim, chat) tidak
        // membungkus $request->validate() dalam try/catch, jadi error
        // 422-nya lolos ke format default Laravel yang tidak punya field
        // `success` — frontend web & mobile selalu cek `!json.success`
        // lalu baca `json.message`, jadi field ini wajib selalu ada.
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        // Jaring pengaman terakhir untuk SEMUA exception lain di /api/*
        // (404 route tidak ditemukan, 500 dari query/DB yang tidak
        // ter-catch di controller, dll) — supaya tidak ada endpoint yang
        // balik ke HTML error page atau JSON tanpa field `success`.
        // Pesan generik dipakai untuk error 500 saat APP_DEBUG=false,
        // supaya detail internal (stack trace, nama tabel, dst) tidak
        // bocor ke publik.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            $message = ($status >= 500 && !config('app.debug'))
                ? 'Terjadi kesalahan pada server. Silakan coba lagi.'
                : $e->getMessage();

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        });
    })->create();