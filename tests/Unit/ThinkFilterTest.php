<?php

namespace Tests\Unit;

use App\Services\Llm\ThinkFilter;
use PHPUnit\Framework\TestCase;

class ThinkFilterTest extends TestCase
{
    public function test_strip_removes_reasoning_blocks(): void
    {
        $this->assertSame('Jawaban.', ThinkFilter::strip("<think>\nlangkah 1\n</think>\n\nJawaban."));
        $this->assertSame('Tanpa blok.', ThinkFilter::strip('Tanpa blok.'));
        $this->assertSame('Awal', ThinkFilter::strip('Awal <think>tidak ditutup'));
    }

    public function test_push_handles_tags_split_across_chunks(): void
    {
        $filter = new ThinkFilter;
        $out = '';
        foreach (['<th', 'ink>ra', 'hasia</th', 'ink>Ha', 'lo <', 'b>'] as $chunk) {
            $out .= $filter->push($chunk);
        }
        $out .= $filter->flush();

        $this->assertSame('Halo <b>', $out);
    }
}
