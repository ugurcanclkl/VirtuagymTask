<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $data], $status);
    }
}
