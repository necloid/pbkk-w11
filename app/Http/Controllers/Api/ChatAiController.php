<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChatAiRequest;
use App\Http\Resources\ChatLogResource;
use App\Models\ChatLog;
use App\Models\User;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Exceptions\LlmProviderException;
use App\Services\Llm\Exceptions\LlmUnavailableException;
use App\Services\Llm\LlmManager;
use App\Services\Llm\LlmResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAiController extends Controller
{
    public function __construct(private readonly LlmManager $llm) {}

    /**
     * POST /api/chat-ai
     *
     * Kirim prompt ke LLM. Dengan "stream": true jawaban dialirkan
     * sebagai Server-Sent Events, tanpa itu dikembalikan utuh sebagai JSON.
     */
    public function store(ChatAiRequest $request): JsonResponse|StreamedResponse
    {
        $prompt = $request->validated('prompt');

        if ($request->wantsStream()) {
            return $this->stream($request->user(), $prompt);
        }

        $startedAt = hrtime(true);
        $result = $this->llm->chat($prompt);

        $log = $this->record($request->user(), $prompt, $result, $startedAt, streamed: false);

        return (new ChatLogResource($log))->response()->setStatusCode(201);
    }

    /**
     * GET /api/chat-ai/history
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $logs = $request->user()->chatLogs()->latest('id')->paginate($perPage);

        return ChatLogResource::collection($logs)->additional(['status' => 'success']);
    }

    /**
     * GET /api/chat-ai/history/{id}
     *
     * Dicari lewat relasi user agar riwayat milik user lain tidak bisa
     * dibuka hanya dengan menebak ID (mencegah IDOR).
     */
    public function show(Request $request, int $id): ChatLogResource
    {
        return new ChatLogResource($request->user()->chatLogs()->findOrFail($id));
    }

    private function stream(User $user, string $prompt): StreamedResponse
    {
        return response()->stream(function () use ($user, $prompt) {
            $startedAt = hrtime(true);

            try {
                $result = $this->llm->stream(
                    $prompt,
                    onDelta: fn (string $delta) => $this->sse('delta', ['content' => $delta]),
                    onStart: fn (LlmProvider $provider, bool $isFallback) => $this->sse('meta', [
                        'provider' => $provider->name(),
                        'model' => $provider->model(),
                        'is_fallback' => $isFallback,
                    ]),
                );
            } catch (LlmUnavailableException|LlmProviderException $e) {
                $this->sse('error', [
                    'status' => 'error',
                    'message' => $e instanceof LlmUnavailableException
                        ? $e->getMessage()
                        : 'Aliran jawaban AI terputus di tengah jalan. Silakan kirim ulang prompt.',
                ]);

                return;
            }

            $log = $this->record($user, $prompt, $result, $startedAt, streamed: true);

            $this->sse('done', (new ChatLogResource($log))->response()->getData(true));
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function record(User $user, string $prompt, LlmResult $result, int $startedAt, bool $streamed): ChatLog
    {
        return $user->chatLogs()->create([
            'prompt' => $prompt,
            'ai_response' => $result->content,
            'model_used' => $result->model,
            'provider' => $result->provider,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'total_tokens' => $result->totalTokens(),
            'is_fallback' => $result->isFallback,
            'is_streamed' => $streamed,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
