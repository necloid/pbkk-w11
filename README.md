# Sentinel Hub: Secure LLM Chat API Hub

Tugas kelompok Pertemuan 11, **Pemrograman Berbasis Kerangka Kerja (PBKK)**, Teknik Informatika ITS.

Sentinel Hub adalah backend API Laravel yang melayani chat ke LLM. Setiap prompt melewati token Sanctum dan validasi FormRequest, lalu diteruskan ke **Ollama lokal** (`qwen3:14b`). Kalau Ollama mati, request dialihkan otomatis ke **Senopati AI ITS**. Semua percakapan dicatat ke database dan dikembalikan sebagai JSON yang dibungkus API Resource, atau dialirkan lewat Server-Sent Events.

Tema kelompok: **agen keamanan siber**. Lewat system prompt, AI diarahkan untuk menganalisis kode dan melaporkan kerentanannya.

| NRP | Nama |
|---|---|
| 5025241046 | Mikail Ibrahim Hakim |
| 5025241094 | Fayza Lathifah Humam |
| 5025241097 | Adriel Mahira Dharma |
| 5025241118 | Muhammad Adi Anugerah Arrahman |
| 5025241164 | Alfianz Risqia Ilahi Loven Kary |

---

## Pemetaan ke spesifikasi tugas

| Spesifikasi | Implementasi |
|---|---|
| Rute `POST /api/chat-ai` di bawah `auth:sanctum` | [`routes/api.php`](routes/api.php) |
| Form Request: prompt wajib string, maks. 1000 karakter | [`app/Http/Requests/Api/ChatAiRequest.php`](app/Http/Requests/Api/ChatAiRequest.php) |
| Log DB: user ID, prompt, jawaban AI, model, token | Migration [`create_chat_logs_table`](database/migrations/2026_11_16_083628_create_chat_logs_table.php), model [`ChatLog`](app/Models/ChatLog.php) |
| Respon JSON dibungkus API Resource | [`app/Http/Resources/ChatLogResource.php`](app/Http/Resources/ChatLogResource.php) |
| Koneksi ke Ollama / Senopati AI ITS | [`app/Services/Llm`](app/Services/Llm) |
| **Challenge 1**: response streaming (SSE) | `"stream": true` di `POST /api/chat-ai` ([`ChatAiController::stream`](app/Http/Controllers/Api/ChatAiController.php)) |
| **Challenge 2**: system prompt injection sesuai tema | `LLM_SYSTEM_PROMPT` di [`config/llm.php`](config/llm.php), disuntikkan oleh [`LlmManager`](app/Services/Llm/LlmManager.php) |
| **Challenge 3**: fallback otomatis ke Senopati | [`LlmManager::chat()` / `stream()`](app/Services/Llm/LlmManager.php) |

Tambahan keamanan: rate limit (login 10/menit per IP, chat 20/menit per user), semua error `/api/*` selalu berbentuk JSON, riwayat dicari lewat relasi user agar tidak bisa dibuka dengan menebak ID (IDOR), dan blok `<think>` dari qwen3 dibuang di server.

---

## Menjalankan proyek

**Kebutuhan:** PHP 8.2 atau lebih baru (ekstensi `pdo_sqlite` atau `pdo_mysql`), Composer 2, dan [Ollama](https://ollama.com/download).

```bash
git clone https://github.com/necloid/pbkk-w11.git
cd pbkk-w11

composer install
cp .env.example .env
php artisan key:generate

# SQLite (default). Untuk MySQL, ubah DB_* di .env.
touch database/database.sqlite        # Windows: type nul > database\database.sqlite
php artisan migrate

# Siapkan Ollama di terminal lain
ollama pull qwen3:14b
ollama serve

php artisan serve
```

Buka `http://localhost:8000` untuk masuk ke konsol chat: login atau daftar, kirim prompt (dengan atau tanpa streaming), dan lihat riwayat. Konsol ini memakai API yang sama seperti Postman, dengan token Sanctum. Antarmuka Livewire menyusul di Pertemuan 12.

### Konfigurasi LLM (`.env`)

| Variabel | Default | Keterangan |
|---|---|---|
| `LLM_PRIMARY` | `ollama` | Provider yang dicoba pertama |
| `LLM_FALLBACK` | `senopati` | Provider cadangan bila yang utama gagal |
| `LLM_TEMPERATURE` | `0.2` | Rendah agar analisis kode tidak mengarang |
| `LLM_SYSTEM_PROMPT` | agen siber ITS | Kosongkan untuk memakai prompt bawaan di `config/llm.php` |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | |
| `OLLAMA_MODEL` | `qwen3:14b` | |
| `SENOPATI_BASE_URL` | `https://senopati.its.ac.id/llm-gateway/v1` | Gateway berformat OpenAI Chat Completions |
| `SENOPATI_API_KEY` | kosong | Token ITS. Selama kosong, fallback dilewati |
| `SENOPATI_MODEL` | `qwen3:14b` | |

Senopati dipanggil di `{SENOPATI_BASE_URL}/chat/completions` dengan header `Authorization: Bearer {SENOPATI_API_KEY}`. Kalau URL gateway dari ITS berbeda, cukup ganti `SENOPATI_BASE_URL`.

---

## API Contract

Base URL: `http://localhost:8000/api`. Semua request dan respon memakai JSON. Endpoint bertanda 🔒 wajib mengirim header:

```
Authorization: Bearer <token>
```

### Ringkasan endpoint

| Method | URL | Auth | Fungsi |
|---|---|---|---|
| POST | `/api/register` | | Daftar akun dan langsung mendapat token |
| POST | `/api/login` | | Tukar email + password dengan token |
| GET | `/api/user` | 🔒 | Profil pemilik token |
| POST | `/api/logout` | 🔒 | Cabut token yang sedang dipakai |
| POST | `/api/chat-ai` | 🔒 | Kirim prompt ke LLM (JSON atau SSE) |
| GET | `/api/chat-ai/history` | 🔒 | Riwayat chat milik user, dengan paginasi |
| GET | `/api/chat-ai/history/{id}` | 🔒 | Detail satu riwayat |
| GET | `/api/status` | | Status online Ollama dan Senopati |

### `POST /api/register`

| Field | Tipe | Aturan |
|---|---|---|
| `name` | string | wajib, maks. 100 |
| `email` | string | wajib, email, unik |
| `password` | string | wajib, min. 8, huruf dan angka |
| `password_confirmation` | string | harus sama dengan `password` |
| `device_name` | string | opsional, nama token |

Respon `201 Created` (bentuknya sama dengan login).

### `POST /api/login`

```json
{ "email": "fayza@its.ac.id", "password": "rahasia123", "device_name": "postman" }
```

Respon `200 OK`:

```json
{
  "status": "success",
  "data": {
    "token": "3|Gk2wQ...a9f",
    "token_type": "Bearer",
    "user": { "id": 1, "name": "Fayza Lathifah Humam", "email": "fayza@its.ac.id", "created_at": "2026-11-16 09:00:00" }
  }
}
```

Password salah: `422` dengan `errors.email`.

### `POST /api/chat-ai` 🔒

| Field | Tipe | Aturan |
|---|---|---|
| `prompt` | string | wajib, maks. 1000 karakter (spasi di awal/akhir dibuang) |
| `stream` | boolean | opsional. `true` untuk menerima Server-Sent Events |

```bash
curl -X POST http://localhost:8000/api/chat-ai \
  -H "Authorization: Bearer 3|Gk2wQ...a9f" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"Jelaskan middleware!"}'
```

Respon `201 Created`:

```json
{
  "data": {
    "session_id": 12,
    "model_used": "qwen3:14b",
    "provider": "ollama",
    "is_fallback": false,
    "prompt": "Jelaskan middleware!",
    "ai_response": "Middleware adalah...",
    "tokens": { "prompt": 88, "completion": 214, "total": 302 },
    "duration_ms": 4120,
    "timestamp": "2026-11-16 09:30:00"
  },
  "status": "success"
}
```

`session_id` adalah ID baris di tabel `chat_logs`. Jika jawaban berasal dari Senopati karena Ollama mati, nilainya `"provider": "senopati"` dan `"is_fallback": true`.

#### Mode streaming (`"stream": true`)

Respon `200` dengan `Content-Type: text/event-stream`. Urutan event:

```
event: meta
data: {"provider":"ollama","model":"qwen3:14b","is_fallback":false}

event: delta
data: {"content":"Middleware "}

event: delta
data: {"content":"adalah..."}

event: done
data: {"data":{"session_id":13, ... sama seperti respon JSON ...},"status":"success"}
```

Bila Ollama gagal sebelum kata pertama terkirim, muncul event `meta` kedua dengan `"provider":"senopati","is_fallback":true`. Bila semua provider gagal, event terakhir adalah `error`:

```
event: error
data: {"status":"error","message":"Layanan AI sedang tidak tersedia. ..."}
```

### `GET /api/chat-ai/history` 🔒

Query `per_page` (1–50, default 15) dan `page`. Hanya mengembalikan riwayat milik pemilik token, terbaru lebih dulu.

```json
{
  "data": [ { "session_id": 13, "model_used": "qwen3:14b", "prompt": "...", "ai_response": "...", "...": "..." } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 15, "total": 2, "...": "..." },
  "status": "success"
}
```

### `GET /api/chat-ai/history/{id}` 🔒

Satu riwayat dengan bentuk `data` yang sama. Riwayat milik user lain menghasilkan `404`.

### `GET /api/status`

```json
{
  "status": "success",
  "data": {
    "providers": [
      { "name": "ollama", "model": "qwen3:14b", "role": "primary", "online": true },
      { "name": "senopati", "model": "qwen3:14b", "role": "fallback", "online": false }
    ],
    "checked_at": "2026-11-16 09:30:00"
  }
}
```

### Format error

Semua error di `/api/*` dikembalikan sebagai JSON, walau klien tidak mengirim `Accept: application/json`.

| Kode | Kapan | Contoh body |
|---|---|---|
| 401 | Token tidak ada atau tidak valid | `{"status":"error","message":"Token tidak ada atau tidak valid. ..."}` |
| 404 | Riwayat tidak ditemukan / bukan milik user | `{"status":"error","message":"Riwayat chat tidak ditemukan."}` |
| 422 | Validasi gagal | `{"status":"error","message":"Prompt maksimal 1000 karakter.","errors":{"prompt":["Prompt maksimal 1000 karakter."]}}` |
| 429 | Melebihi rate limit | `{"status":"error","message":"Terlalu banyak request. ..."}` |
| 503 | Ollama dan Senopati sama-sama gagal | `{"status":"error","message":"Layanan AI sedang tidak tersedia. ..."}` |

---

## Demo dengan Postman

Import [`docs/Sentinel-Hub.postman_collection.json`](docs/Sentinel-Hub.postman_collection.json). Jalankan **Auth → Login** (atau Register); token otomatis tersimpan ke variabel `{{token}}`. Folder **Uji keamanan** berisi request yang seharusnya ditolak: tanpa token (401), prompt lebih dari 1000 karakter (422), dan prompt kosong (422).

## Pengujian

```bash
php artisan test
```

Feature test memalsukan Ollama dan Senopati dengan `Http::fake()`, sehingga tidak butuh server AI. Yang diuji: 401 tanpa token, validasi prompt, isi log di database, injeksi system prompt, fallback JSON dan SSE, 503 saat semua provider gagal, serta isolasi riwayat antar user.

## Struktur penting

```
app/
├── Http/
│   ├── Controllers/Api/   AuthController, ChatAiController, StatusController
│   ├── Middleware/        ForceJsonResponse
│   ├── Requests/Api/      ChatAiRequest, LoginRequest, RegisterRequest
│   └── Resources/         ChatLogResource, UserResource
├── Models/                User (HasApiTokens), ChatLog
└── Services/Llm/          LlmManager (system prompt + fallback), ThinkFilter,
                           Providers/OllamaProvider, Providers/OpenAiCompatibleProvider
config/llm.php             provider, model, system prompt
routes/api.php             rute API
resources/views/           konsol chat (Blade + public/js/console.js)
tests/Feature, tests/Unit
```
