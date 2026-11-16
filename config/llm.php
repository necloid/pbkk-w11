<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Urutan Provider LLM
    |--------------------------------------------------------------------------
    |
    | Provider utama dicoba lebih dulu. Jika gagal (server mati, timeout,
    | atau respon error), permintaan otomatis dialihkan ke provider fallback.
    | Nilai yang didukung: "ollama", "senopati".
    |
    */

    'primary' => env('LLM_PRIMARY', 'ollama'),

    'fallback' => env('LLM_FALLBACK', 'senopati'),

    'temperature' => (float) env('LLM_TEMPERATURE', 0.2),

    /*
    |--------------------------------------------------------------------------
    | System Prompt Tema Kelompok
    |--------------------------------------------------------------------------
    |
    | Disuntikkan sebagai pesan "system" di setiap percakapan agar AI selalu
    | berperilaku sebagai agen keamanan siber.
    |
    */

    'system_prompt' => env('LLM_SYSTEM_PROMPT', implode(' ', [
        'Anda adalah Sentinel, agen keamanan siber ITS.',
        'Analisis kode, konfigurasi, atau pertanyaan yang diberikan dari sudut pandang keamanan aplikasi.',
        'Jika ada kerentanan, sebutkan jenisnya (misalnya SQL injection, XSS, IDOR), baris atau bagian yang bermasalah,',
        'tingkat risikonya (rendah, sedang, tinggi, kritis), dan cara memperbaikinya beserta contoh kode.',
        'Jika tidak ditemukan kerentanan, katakan dengan jelas lalu beri saran hardening singkat.',
        'Jawab dalam bahasa Indonesia yang ringkas dan teknis. Tolak permintaan untuk membuat malware atau menyerang sistem orang lain.',
    ])),

    'providers' => [

        'ollama' => [
            'driver' => 'ollama',
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'qwen3:14b'),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
            'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 3),
        ],

        'senopati' => [
            'driver' => 'openai',
            'base_url' => env('SENOPATI_BASE_URL', 'https://senopati.its.ac.id/llm-gateway/v1'),
            'api_key' => env('SENOPATI_API_KEY'),
            'model' => env('SENOPATI_MODEL', 'qwen3:14b'),
            'timeout' => (int) env('SENOPATI_TIMEOUT', 120),
            'connect_timeout' => (int) env('SENOPATI_CONNECT_TIMEOUT', 5),
        ],

    ],

];
