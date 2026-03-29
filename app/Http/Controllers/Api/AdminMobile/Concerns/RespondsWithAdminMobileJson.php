<?php

namespace App\Http\Controllers\Api\AdminMobile\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsWithAdminMobileJson
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function successResponse(
        string $message,
        ?array $data = null,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}
