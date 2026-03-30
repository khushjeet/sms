<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'module' => \App\Http\Middleware\CheckModuleAccess::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }

            $driverCode = (string) ($exception->errorInfo[1] ?? '');
            $message = 'A database error occurred while processing your request.';
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;

            if ($driverCode === '1452') {
                $message = 'The record could not be saved because it references missing or invalid related data.';
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;

                if (str_contains($exception->getMessage(), 'compiled_marks_section_id_foreign')) {
                    $message = 'Unable to save marks because one or more selected students do not have a section assigned in their enrollment record.';
                }
            }

            return response()->json([
                'message' => $message,
            ], $status);
        });
    })->create();
