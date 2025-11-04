<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        // Always return JSON for API routes
        if ($request->is('api/*')) {
            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof NotFoundHttpException) {
                return response()->json([
                    'message' => 'API route not found'
                ], 404);
            }

            if ($e->getMessage() == 'Unauthenticated.' || $e->getMessage() == 'unauthenticated.') {
                return response()->json([
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            return response()->json([
                'message' => $e->getMessage(),
            ], method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
        }

        return parent::render($request, $e);
    }
}
