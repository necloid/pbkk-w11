<?php

namespace App\Services\Llm\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Semua provider (utama dan fallback) gagal menjawab.
 */
class LlmUnavailableException extends RuntimeException
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(public readonly array $failures = [])
    {
        parent::__construct('Layanan AI sedang tidak tersedia. Pastikan Ollama berjalan (ollama serve) atau SENOPATI_API_KEY sudah diisi, lalu coba lagi.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $this->getMessage(),
        ], 503);
    }
}
