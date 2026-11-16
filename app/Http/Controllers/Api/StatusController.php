<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Llm\LlmManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class StatusController extends Controller
{
    /**
     * GET /api/status
     *
     * Status publik provider LLM (online/offline), di-cache 15 detik
     * supaya halaman depan tidak membebani server AI.
     */
    public function __invoke(LlmManager $llm): JsonResponse
    {
        $providers = Cache::remember('llm-status', 15, fn () => $llm->status());

        return response()->json([
            'status' => 'success',
            'data' => [
                'providers' => $providers,
                'checked_at' => now()->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
