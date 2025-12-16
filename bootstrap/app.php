<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Register middleware aliases
        $middleware->alias([
            'auth.cookie' => \App\Http\Middleware\AuthenticateFromCookie::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Логируем все исключения для отладки
        $exceptions->report(function (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('Exception occurred', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        });

        // Добавляем CORS заголовки к ответам об ошибках
        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request) {
            // Получаем origin из запроса
            $origin = $request->header('Origin');

            // Список разрешённых origins (должен совпадать с config/cors.php)
            $allowedOrigins = [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://localhost:5040',
                'http://localhost:5137',
                'http://127.0.0.1:5137',
                'https://bonus5.ru',
                'https://www.bonus5.ru',
                'https://auth.bonus5.ru',
                'https://bonus.band',
                'https://www.bonus.band',
                'https://admin.bonus.band',
                'https://auth.bonus.band',
                'https://rubonus.info',
                'https://rubonus.pro',
                'https://www.rubonus.pro',
                'https://auth.rubonus.pro',
                'https://mebelmobile.ru',
                'https://www.mebelmobile.ru',
            ];

            if ($origin && in_array($origin, $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
            }

            return $response;
        });
    })->create();
