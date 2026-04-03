<?php

namespace App\Http\Controllers\Meta;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataDeletionCallbackController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('post')) {
            return response()->json([
                'success' => true,
                'message' => 'Data deletion callback request received.',
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data deletion callback endpoint is active.',
        ], 200);
    }
}
