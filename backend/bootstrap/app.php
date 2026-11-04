<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Allow browser-hosted clients (Flutter web dev server) on localhost
        // to call the API cross-origin. Native apps are unaffected by CORS.
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, $request) {
            return response()->json([
                'message' => 'The ' . $request->method() . ' method is not supported for route ' . $request->path() . '. Supported methods: ' . implode(', ', $e->getHeaders()['Allow'] ?? ['POST']) . '.',
                'status' => 'error',
            ], 405);
        });
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
