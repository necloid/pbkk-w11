@extends('layouts.app', ['title' => 'Sentinel Hub'])

@section('content')
<main class="console" data-console>
    <aside class="sidebar">
        {{-- Belum login --}}
        <section data-auth>
            <h1 class="panel-title">Masuk untuk mendapat token</h1>
            <p class="hint">Token Sanctum dipakai sebagai header Authorization di setiap request.</p>

            <div class="seg" role="group" aria-label="Pilih mode">
                <button type="button" data-mode="login" aria-pressed="true">Login</button>
                <button type="button" data-mode="register" aria-pressed="false">Daftar</button>
            </div>

            <form data-auth-form novalidate>
                <div class="field" data-only="register" hidden>
                    <label for="name">Nama</label>
                    <input class="input" id="name" name="name" autocomplete="name">
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input class="input" id="email" name="email" type="email" autocomplete="email" required>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <div class="field" data-only="register" hidden>
                    <label for="password_confirmation">Ulangi password</label>
                    <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                </div>
                <p class="form-error" data-auth-error role="alert"></p>
                <button class="btn" type="submit" style="width:100%" data-auth-submit>Login</button>
            </form>
        </section>

        {{-- Sudah login --}}
        <section class="whoami" data-session hidden>
            <div>
                <h1 class="panel-title" data-user-name></h1>
                <p class="hint" data-user-email></p>
            </div>
            <div class="token-box">
                <code data-token></code>
                <button class="link-btn" type="button" data-copy-token>Salin</button>
            </div>
            <div><button class="btn btn--ghost" type="button" data-logout>Logout dan cabut token</button></div>
        </section>

        <section data-history-wrap hidden>
            <h2 class="panel-title" style="font-size:1rem">Riwayat</h2>
            <ul class="history" data-history></ul>
        </section>

        <section>
            <h2 class="panel-title" style="font-size:1rem">Status provider</h2>
            <div class="status-row" data-provider-status><div>Memeriksa…</div></div>
        </section>
    </aside>

    <section class="chat" aria-label="Percakapan">
        <div class="transcript" data-transcript aria-live="polite">
            <div class="welcome" data-welcome>
                <p class="eyebrow">POST /api/chat-ai</p>
                <h2 class="heading">Tempel kode yang ingin kamu periksa</h2>
                <p class="lede">Sentinel akan menunjuk bagian yang rentan, menilai risikonya, dan memberi contoh perbaikan. Coba salah satu contoh ini:</p>
                <div class="examples">
                    <button type="button" data-example>$user = DB::select("SELECT * FROM users WHERE email = '" . $request->email . "'");</button>
                    <button type="button" data-example>Route::get('/invoice/{id}', fn ($id) => Invoice::find($id));</button>
                    <button type="button" data-example>Apa bedanya token Sanctum dengan session cookie dari sisi keamanan?</button>
                </div>
            </div>
        </div>

        <form class="composer" data-composer>
            <label class="sr-only" for="prompt">Prompt</label>
            <textarea id="prompt" name="prompt" placeholder="Login dulu untuk mulai bertanya" disabled></textarea>
            <div class="composer__bar">
                <span class="counter" data-counter>0 / 1000</span>
                <label class="toggle"><input type="checkbox" data-stream checked> Streaming</label>
                <span class="locked" data-locked>Endpoint terkunci tanpa token</span>
                <button class="btn" type="submit" data-send disabled>Kirim prompt</button>
            </div>
        </form>
    </section>
</main>
@endsection

@push('scripts')
    <script src="{{ asset('js/console.js') }}" defer></script>
@endpush
