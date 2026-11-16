// Konsol Sentinel: klien kecil untuk API Sanctum + chat-ai (JSON dan SSE).
(function () {
    'use strict';

    const MAX = 1000;
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const el = {
        auth: $('[data-auth]'),
        form: $('[data-auth-form]'),
        error: $('[data-auth-error]'),
        submit: $('[data-auth-submit]'),
        session: $('[data-session]'),
        name: $('[data-user-name]'),
        email: $('[data-user-email]'),
        token: $('[data-token]'),
        historyWrap: $('[data-history-wrap]'),
        history: $('[data-history]'),
        providers: $('[data-provider-status]'),
        transcript: $('[data-transcript]'),
        welcome: $('[data-welcome]'),
        composer: $('[data-composer]'),
        prompt: $('#prompt'),
        counter: $('[data-counter]'),
        stream: $('[data-stream]'),
        locked: $('[data-locked]'),
        send: $('[data-send]'),
    };

    /* ---------- Penyimpanan token (localStorage bisa tidak tersedia) ---------- */
    const store = {
        get() { try { return localStorage.getItem('sentinel.token'); } catch { return null; } },
        set(v) { try { localStorage.setItem('sentinel.token', v); } catch { /* abaikan */ } },
        clear() { try { localStorage.removeItem('sentinel.token'); } catch { /* abaikan */ } },
    };

    let token = store.get();
    let mode = 'login';
    let busy = false;

    async function api(path, { method = 'GET', body } = {}) {
        const headers = { Accept: 'application/json' };
        if (body) headers['Content-Type'] = 'application/json';
        if (token) headers.Authorization = `Bearer ${token}`;
        const res = await fetch(`/api${path}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
        let json = null;
        try { json = await res.json(); } catch { /* respon bukan JSON */ }
        return { res, json };
    }

    function firstError(json, fallback) {
        if (json && json.errors) {
            const first = Object.values(json.errors)[0];
            if (first && first[0]) return first[0];
        }
        return (json && json.message) || fallback;
    }

    /* ---------- Mode login / daftar ---------- */
    $$('[data-mode]').forEach((btn) => btn.addEventListener('click', () => {
        mode = btn.dataset.mode;
        $$('[data-mode]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
        $$('[data-only="register"]').forEach((f) => { f.hidden = mode !== 'register'; });
        el.submit.textContent = mode === 'login' ? 'Login' : 'Daftar dan dapatkan token';
        $('#password').autocomplete = mode === 'login' ? 'current-password' : 'new-password';
        el.error.textContent = '';
    }));

    el.form.addEventListener('submit', async (e) => {
        e.preventDefault();
        el.error.textContent = '';
        el.submit.disabled = true;
        const data = Object.fromEntries(new FormData(el.form));
        data.device_name = 'sentinel-console';
        if (mode === 'login') { delete data.name; delete data.password_confirmation; }

        try {
            const { res, json } = await api(mode === 'login' ? '/login' : '/register', { method: 'POST', body: data });
            if (!res.ok) {
                el.error.textContent = firstError(json, `Gagal (${res.status}).`);
                return;
            }
            token = json.data.token;
            store.set(token);
            el.form.reset();
            showSession(json.data.user);
            loadHistory();
        } catch {
            el.error.textContent = 'Server tidak dapat dihubungi. Pastikan php artisan serve berjalan.';
        } finally {
            el.submit.disabled = false;
        }
    });

    $('[data-logout]').addEventListener('click', async () => {
        await api('/logout', { method: 'POST' }).catch(() => {});
        signOut();
    });

    $('[data-copy-token]').addEventListener('click', async (e) => {
        try {
            await navigator.clipboard.writeText(token);
            e.target.textContent = 'Tersalin';
        } catch {
            e.target.textContent = 'Gagal menyalin';
        }
        setTimeout(() => { e.target.textContent = 'Salin'; }, 1500);
    });

    function signOut() {
        token = null;
        store.clear();
        el.session.hidden = true;
        el.historyWrap.hidden = true;
        el.auth.hidden = false;
        setComposer(false);
    }

    function showSession(user) {
        el.auth.hidden = true;
        el.session.hidden = false;
        el.historyWrap.hidden = false;
        el.name.textContent = user.name;
        el.email.textContent = user.email;
        el.token.textContent = token.replace(/^(\d+\|.{6}).*(.{4})$/, '$1…$2');
        el.token.title = 'Token Sanctum (disingkat)';
        setComposer(true);
    }

    function setComposer(enabled) {
        el.prompt.disabled = !enabled;
        el.prompt.placeholder = enabled
            ? 'Tempel kode atau tulis pertanyaan keamanan (Ctrl + Enter untuk mengirim)'
            : 'Login dulu untuk mulai bertanya';
        el.locked.hidden = enabled;
        updateCounter();
    }

    /* ---------- Riwayat ---------- */
    async function loadHistory() {
        const { res, json } = await api('/chat-ai/history?per_page=20');
        if (res.status === 401) { signOut(); return; }
        if (!res.ok) return;
        el.history.replaceChildren();
        if (!json.data.length) {
            const li = document.createElement('li');
            li.className = 'empty';
            li.textContent = 'Belum ada percakapan. Kirim prompt pertamamu.';
            el.history.append(li);
            return;
        }
        json.data.forEach((log) => el.history.append(historyItem(log)));
    }

    function historyItem(log) {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.innerHTML = '<span class="when"></span><span class="what"></span>';
        btn.querySelector('.when').textContent = `#${log.session_id}  ${log.timestamp}  ${log.provider}`;
        btn.querySelector('.what').textContent = log.prompt;
        btn.addEventListener('click', () => {
            el.welcome.hidden = true;
            addUser(log.prompt);
            const ai = addAi(log);
            ai.body.innerHTML = markdown(log.ai_response);
            attachJson(ai.root, { data: log, status: 'success' });
            scrollDown();
        });
        li.append(btn);
        return li;
    }

    /* ---------- Status provider ---------- */
    fetch('/api/status', { headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((json) => {
            el.providers.replaceChildren(...json.data.providers.map((p) => {
                const row = document.createElement('div');
                row.innerHTML = `<i class="dot${p.online ? ' dot--on' : ''}"></i><span></span>`;
                row.querySelector('span').textContent =
                    `${p.name} (${p.role === 'primary' ? 'utama' : 'cadangan'}) ${p.online ? 'online' : 'offline'}`;
                return row;
            }));
        })
        .catch(() => { el.providers.textContent = 'Status tidak dapat dicek.'; });

    /* ---------- Composer ---------- */
    function updateCounter() {
        const n = el.prompt.value.length;
        el.counter.textContent = `${n} / ${MAX}`;
        el.counter.classList.toggle('counter--over', n > MAX);
        el.send.disabled = busy || !token || n === 0 || n > MAX || !el.prompt.value.trim();
    }
    el.prompt.addEventListener('input', updateCounter);
    el.prompt.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) el.composer.requestSubmit();
    });

    $$('[data-example]').forEach((b) => b.addEventListener('click', () => {
        if (!token) { $('#email').focus(); return; }
        el.prompt.value = b.textContent;
        updateCounter();
        el.prompt.focus();
    }));

    el.composer.addEventListener('submit', async (e) => {
        e.preventDefault();
        const prompt = el.prompt.value.trim();
        if (!prompt || busy) return;

        busy = true;
        updateCounter();
        el.welcome.hidden = true;
        addUser(prompt);
        el.prompt.value = '';
        updateCounter();

        try {
            if (el.stream.checked) {
                await sendStream(prompt);
            } else {
                await sendJson(prompt);
            }
            loadHistory();
        } catch {
            addError('Koneksi ke server terputus. Coba kirim ulang.');
        } finally {
            busy = false;
            updateCounter();
            el.prompt.focus();
        }
    });

    async function sendJson(prompt) {
        const ai = addAi({ provider: '…', model_used: 'menunggu jawaban' });
        ai.body.innerHTML = '<span class="caret"></span>';
        const { res, json } = await api('/chat-ai', { method: 'POST', body: { prompt } });
        if (res.status === 401) { ai.root.remove(); addError(json.message); signOut(); return; }
        if (!res.ok) { ai.root.remove(); addError(firstError(json, `Gagal (${res.status}).`)); return; }
        fillMeta(ai, json.data);
        ai.body.innerHTML = markdown(json.data.ai_response);
        attachJson(ai.root, json);
        scrollDown();
    }

    async function sendStream(prompt) {
        const res = await fetch('/api/chat-ai', {
            method: 'POST',
            headers: {
                Accept: 'text/event-stream',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify({ prompt, stream: true }),
        });

        if (!res.ok || !res.body) {
            let json = null;
            try { json = await res.json(); } catch { /* bukan JSON */ }
            addError(firstError(json, `Gagal (${res.status}).`));
            if (res.status === 401) signOut();
            return;
        }

        const ai = addAi({ provider: '…', model_used: 'menghubungkan' });
        let text = '';
        ai.body.innerHTML = '<span class="caret"></span>';

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });

            let idx;
            while ((idx = buffer.indexOf('\n\n')) !== -1) {
                const block = buffer.slice(0, idx);
                buffer = buffer.slice(idx + 2);
                const event = (block.match(/^event: (.+)$/m) || [])[1];
                const raw = (block.match(/^data: (.+)$/m) || [])[1];
                if (!event || !raw) continue;
                const data = JSON.parse(raw);

                if (event === 'meta') {
                    ai.meta.textContent = `${data.provider}  ${data.model}${data.is_fallback ? '  fallback' : ''}`;
                } else if (event === 'delta') {
                    text += data.content;
                    ai.body.innerHTML = withCaret(markdown(text));
                    scrollDown();
                } else if (event === 'done') {
                    fillMeta(ai, data.data);
                    ai.body.innerHTML = markdown(data.data.ai_response);
                    attachJson(ai.root, data);
                } else if (event === 'error') {
                    if (!text) ai.root.remove();
                    else ai.body.innerHTML = markdown(text);
                    addError(data.message);
                }
            }
        }
        scrollDown();
    }

    /* ---------- Render pesan ---------- */
    function addUser(prompt) {
        const root = document.createElement('article');
        root.className = 'msg msg--user';
        root.innerHTML = '<div class="msg__who">Kamu</div><div class="msg__body"></div>';
        $('.msg__body', root).textContent = prompt;
        el.transcript.append(root);
        scrollDown();
    }

    function addAi(log) {
        const root = document.createElement('article');
        root.className = 'msg msg--ai';
        root.innerHTML = '<div class="msg__who"><span>Sentinel</span><span data-meta></span></div><div class="msg__body"></div>';
        const ai = { root, body: $('.msg__body', root), meta: $('[data-meta]', root) };
        fillMeta(ai, log);
        el.transcript.append(root);
        return ai;
    }

    function fillMeta(ai, log) {
        const parts = [log.provider, log.model_used];
        if (log.is_fallback) parts.push('fallback');
        if (log.tokens) parts.push(`${log.tokens.total} token`);
        if (log.duration_ms) parts.push(`${(log.duration_ms / 1000).toFixed(1)} dtk`);
        if (log.session_id) parts.push(`#${log.session_id}`);
        ai.meta.textContent = parts.filter(Boolean).join('  ');
    }

    function attachJson(root, json) {
        const d = document.createElement('details');
        d.className = 'msg__json';
        d.innerHTML = '<summary>Lihat respon JSON</summary><pre></pre>';
        $('pre', d).textContent = JSON.stringify(json, null, 2);
        root.append(d);
    }

    function addError(message) {
        const root = document.createElement('article');
        root.className = 'msg msg--error';
        root.innerHTML = '<div class="msg__who">Error</div><div class="msg__body" role="alert"></div>';
        $('.msg__body', root).textContent = message;
        el.transcript.append(root);
        scrollDown();
    }

    function scrollDown() {
        el.transcript.scrollTop = el.transcript.scrollHeight;
        if (window.matchMedia('(max-width: 900px)').matches) {
            el.composer.scrollIntoView({ block: 'end' });
        }
    }

    function withCaret(html) {
        const caret = '<span class="caret"></span>';
        const m = html.match(/(<\/(?:p|li|h4)>|<\/code><\/pre>)(<\/[uo]l>)?$/);
        return m ? html.slice(0, m.index) + caret + html.slice(m.index) : html + caret;
    }

    /* ---------- Markdown minimal (aman: escape dulu) ---------- */
    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function inline(s) {
        return s
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');
    }

    function markdown(src) {
        const parts = escapeHtml(src).split(/```/);
        let html = '';
        parts.forEach((part, i) => {
            if (i % 2 === 1) {
                const code = part.replace(/^[\w+-]*\n/, '');
                html += `<pre><code>${code.replace(/\n$/, '')}</code></pre>`;
                return;
            }
            let list = null;
            const flush = () => { if (list) { html += `</${list}>`; list = null; } };
            part.split(/\n{2,}/).forEach((block) => {
                const lines = block.split('\n');
                let para = [];
                const flushPara = () => { if (para.length) { html += `<p>${inline(para.join('<br>'))}</p>`; para = []; } };
                lines.forEach((line) => {
                    let m;
                    if ((m = line.match(/^#{1,6}\s+(.*)/))) {
                        flushPara(); flush();
                        html += `<h4>${inline(m[1])}</h4>`;
                    } else if ((m = line.match(/^\s*[-*]\s+(.*)/)) || (m = line.match(/^\s*\d+[.)]\s+(.*)/))) {
                        flushPara();
                        const type = /^\s*\d/.test(line) ? 'ol' : 'ul';
                        if (list !== type) { flush(); html += `<${type}>`; list = type; }
                        html += `<li>${inline(m[1])}</li>`;
                    } else if (line.trim()) {
                        flush();
                        para.push(line);
                    }
                });
                flushPara();
                flush();
            });
        });
        return html;
    }

    /* ---------- Mulai ---------- */
    async function boot() {
        if (!token) { setComposer(false); return; }
        const { res, json } = await api('/user').catch(() => ({ res: { ok: false } }));
        if (res.ok) {
            showSession(json.data);
            loadHistory();
        } else {
            signOut();
        }
    }
    boot();
})();
