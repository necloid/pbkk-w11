<?php

namespace App\Services\Llm;

/**
 * Membuang blok penalaran <think>...</think> yang dikeluarkan model
 * seperti qwen3, baik dari teks utuh maupun dari aliran potongan teks.
 */
final class ThinkFilter
{
    private const OPEN = '<think>';

    private const CLOSE = '</think>';

    private bool $inside = false;

    private string $pending = '';

    public static function strip(string $text): string
    {
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text) ?? $text;

        // Blok yang tidak pernah ditutup ikut dibuang.
        $text = preg_replace('/<think>.*$/s', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Terima satu potongan teks, kembalikan bagian yang aman ditampilkan.
     */
    public function push(string $chunk): string
    {
        $buffer = $this->pending.$chunk;
        $this->pending = '';
        $out = '';

        while ($buffer !== '') {
            $tag = $this->inside ? self::CLOSE : self::OPEN;
            $pos = strpos($buffer, $tag);

            if ($pos !== false) {
                if (! $this->inside) {
                    $out .= substr($buffer, 0, $pos);
                }
                $buffer = substr($buffer, $pos + strlen($tag));
                $this->inside = ! $this->inside;

                continue;
            }

            // Simpan ekor yang mungkin awal dari tag, tunggu potongan berikutnya.
            $keep = $this->partialTagLength($buffer, $tag);
            if (! $this->inside) {
                $out .= substr($buffer, 0, strlen($buffer) - $keep);
            }
            $this->pending = $keep > 0 ? substr($buffer, -$keep) : '';
            $buffer = '';
        }

        return $out;
    }

    /**
     * Sisa teks yang tertahan saat aliran selesai.
     */
    public function flush(): string
    {
        $rest = $this->inside ? '' : $this->pending;
        $this->pending = '';

        return $rest;
    }

    private function partialTagLength(string $buffer, string $tag): int
    {
        $max = min(strlen($tag) - 1, strlen($buffer));
        for ($len = $max; $len > 0; $len--) {
            if (str_ends_with($buffer, substr($tag, 0, $len))) {
                return $len;
            }
        }

        return 0;
    }
}
