<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ChatAiRequest extends FormRequest
{
    /**
     * Endpoint sudah dikunci middleware auth:sanctum.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:1000'],
            'stream' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Prompt wajib diisi.',
            'prompt.string' => 'Prompt harus berupa teks.',
            'prompt.max' => 'Prompt maksimal :max karakter.',
            'stream.boolean' => 'Nilai stream harus true atau false.',
        ];
    }

    /**
     * Rapikan spasi di awal/akhir sebelum validasi, sehingga prompt yang
     * isinya hanya spasi ikut ditolak sebagai kosong.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('prompt'))) {
            $this->merge(['prompt' => trim($this->input('prompt'))]);
        }
    }

    public function wantsStream(): bool
    {
        return $this->boolean('stream');
    }
}
