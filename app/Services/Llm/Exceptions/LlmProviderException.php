<?php

namespace App\Services\Llm\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Satu provider gagal menjawab (server mati, timeout, atau respon error).
 */
class LlmProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly bool $deltaEmitted = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$provider}] {$message}", 0, $previous);
    }
}
