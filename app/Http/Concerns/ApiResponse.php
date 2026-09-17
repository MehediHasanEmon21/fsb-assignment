<?php

namespace App\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Throwable;

trait ApiResponse
{
    protected function successResponse(
        mixed $data = null,
        string $message = 'Request successful.',
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    protected function errorResponse(
        string $message,
        int $status,
        array $errors = [],
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    protected function serverErrorResponse(
        Throwable $exception,
        string $message = 'An unexpected error occurred.',
    ): JsonResponse {
        report($exception);

        return $this->errorResponse($message, 500);
    }
}
