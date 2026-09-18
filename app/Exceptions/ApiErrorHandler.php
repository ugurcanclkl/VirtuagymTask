<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorHandler
{
    public static function report(Throwable $error): bool
    {
        // Do not pass the exception object/message: SQL errors may contain bound secrets.
        Log::error('Request failed', [
            'request_id' => request()->attributes->get('request_id'),
            'exception_type' => get_class($error),
        ]);

        return false; // Stop Laravel's default exception/stack-trace logging.
    }

    public static function render(Throwable $error, Request $request): JsonResponse
    {
        $status = 500;
        $code = 9000;
        $message = 'An unexpected error occurred. Please try again.';
        $errors = [];

        if ($error instanceof ApiException) {
            [$status, $code, $message] = [$error->httpStatus, $error->errorCode, $error->getMessage()];
        } elseif ($error instanceof ValidationException) {
            [$status, $code, $message] = [422, 1000, 'The submitted data is invalid.'];
            $errors = $error->errors();
        } elseif ($error instanceof AuthenticationException) {
            [$status, $code, $message] = [401, 1001, 'Authentication is required.'];
        } elseif ($error instanceof HttpExceptionInterface && $error->getStatusCode() < 500) {
            $status = $error->getStatusCode();
            $code = $status;
            $message = match ($status) {
                403 => 'You are not allowed to perform this action.',
                404 => 'The requested resource was not found.',
                405 => 'This HTTP method is not allowed.',
                429 => 'Too many requests. Please try again later.',
                default => 'The request could not be completed.',
            };
        }

        if ($status < 500) {
            Log::notice('Request rejected', [
                'request_id' => $request->attributes->get('request_id'),
                'http_status' => $status,
                'error_code' => $code,
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => $message,
            'data' => ['status_code' => $code],
            'errors' => $errors,
            'request_id' => $request->attributes->get('request_id'),
        ], $status, $error instanceof HttpExceptionInterface ? $error->getHeaders() : []);
    }
}
