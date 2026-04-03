<?php

namespace App\Http\Controllers\Meta;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DataDeletionCallbackController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $confirmationCode = Str::upper(Str::random(12));

        return response()->json([
            'url' => route('meta.data-deletion-status', ['code' => $confirmationCode]),
            'confirmation_code' => $confirmationCode,
        ], 200);
    }

    public function status(string $code): View
    {
        return view('public.data-deletion-status', [
            'confirmationCode' => strtoupper($code),
        ]);
    }
}
