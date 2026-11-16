<?php

namespace App\Http\Resources;

use App\Models\ChatLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChatLog
 */
class ChatLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->id,
            'model_used' => $this->model_used,
            'provider' => $this->provider,
            'is_fallback' => $this->is_fallback,
            'prompt' => $this->prompt,
            'ai_response' => $this->ai_response,
            'tokens' => [
                'prompt' => $this->prompt_tokens,
                'completion' => $this->completion_tokens,
                'total' => $this->total_tokens,
            ],
            'duration_ms' => $this->duration_ms,
            'timestamp' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['status' => 'success'];
    }
}
