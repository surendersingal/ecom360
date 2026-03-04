<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Shorthand alias for successResponse().
     */
    protected function success(mixed $data, string|int $message = 'Request successful', int $code = 200): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($data, $message, $code);
    }

    /**
     * Shorthand alias for errorResponse().
     */
    protected function error(string $message, int $code = 400, array $errors = []): \Illuminate\Http\JsonResponse
    {
        return $this->errorResponse($message, $code, $errors);
    }

    /**
     * Return a standardised success JSON response.
     */
    protected function successResponse(
        mixed $data,
        string|int $message = 'Request successful',
        int $code = 200,
    ): JsonResponse {
        // Allow shorthand: successResponse($data, 201)
        if (is_int($message)) {
            $code = $message;
            $message = 'Request successful';
        }

        return response()->json(
            data: [
                'success' => true,
                'message' => $message,
                'data'    => $data,
            ],
            status: $code,
        );
    }

    /**
     * Return a standardised error JSON response.
     */
    protected function errorResponse(
        string $message,
        int $code,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json(
            data: $payload,
            status: $code,
        );
    }
}
