function chatbotPage() {
    return {
        loading: false,
        input: '',
        messages: [],
        lastUserMessage: '',
        questionPool: [
            'Problem dies apa yang paling sering muncul?',
            'Mesin apa yang paling sering rusak?',
            'Dandori losstime apa yang paling banyak makan waktu?',
            'Problem mesin apa yang paling sering terjadi?',
            'Dies mana yang paling sering breakdown?',
            'Mesin mana yang paling sering breakdown?',
            'Total produksi keseluruhan berapa Pcs?',
            'Penyebab breakdown dies yang paling umum apa?',
            'Rata-rata SPH produksi berapa?',
            'Jenis losstime dandori apa yang paling sering muncul?',
            'Berapa total kejadian breakdown dies?',
            'Ranking mesin paling bermasalah urutannya gimana?',
            'Siapa operator dengan produksi terbanyak?',
            'Berapa jumlah karyawan aktif per departemen?',
        ],
        suggestions: [],

        greeting() {
            return { role: 'assistant', content: 'Halo! Saya OPTIMA AI Assistant. Tanya soal produksi, quality/NG, dandori, breakdown mesin & dies, atau data master apa pun — saya telusuri langsung dari database.', typing: false };
        },

        init() {
            this.messages = [this.greeting()];
            this.shuffleSuggestions();
        },

        shuffleSuggestions() {
            const pool = [...this.questionPool];
            for (let i = pool.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [pool[i], pool[j]] = [pool[j], pool[i]];
            }
            this.suggestions = pool.slice(0, 3);
        },

        askSuggested(question) {
            this.input = question;
            this.send();
        },

        // History in-memory yang dikirim ke server: buang greeting pertama,
        // ambil maksimal 12 turn terakhir, hanya field role+content.
        buildHistory() {
            // 12 turn = konteks lebih dari cukup buat chatbot data, tapi hemat token
            // di percakapan panjang (history di-resend tiap request). Clamp konten ke
            // batas server (max:4000) supaya balasan panjang tak bikin request berikut 422.
            return this.messages
                .slice(1)
                .filter(m => !m.typing)
                .slice(-12)
                .map(m => ({ role: m.role, content: (m.content ?? '').slice(0, 4000) }));
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            this.lastUserMessage = text;
            const history = this.buildHistory();

            this.messages.push({ role: 'user', content: text });
            this.input = '';
            await this.dispatch(text, history);
        },

        async regenerate() {
            if (this.loading || !this.lastUserMessage) return;
            // Buang jawaban bot terakhir, kirim ulang pertanyaan terakhir.
            if (this.messages.length && this.messages[this.messages.length - 1].role === 'assistant') {
                this.messages.pop();
            }
            // Buang giliran user terakhir dari history — ia dikirim ulang lewat field
            // message, jadi kalau ikut di history pertanyaannya dobel.
            const history = this.buildHistory();
            if (history.length && history[history.length - 1].role === 'user') {
                history.pop();
            }
            await this.dispatch(this.lastUserMessage, history);
        },

        async dispatch(text, history) {
            this.loading = true;
            this.$nextTick(() => this.scrollDown());

            try {
                // Coba streaming (SSE) dulu — render progresif. Kalau gagal SEBELUM
                // ada teks yang tampil, jatuh ke endpoint non-stream (fallback aman).
                const streamed = await this.streamReply(text, history);
                if (!streamed) {
                    const data = await this.postMessage(text, history);
                    this.pushTyped(data.reply);
                    this.applySuggestions(data.suggestions);
                }
            } catch (e) {
                this.messages.push({ role: 'assistant', content: 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.', typing: false });
                this.shuffleSuggestions();
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollDown());
            }
        },

        // POST non-stream (fallback). Lempar error kalau HTTP gagal.
        async postMessage(text, history) {
            const res = await fetch('/chatbot/message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ message: text, history }),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        },

        // Streaming SSE dari /chatbot/stream. Balikin true kalau ada teks yang
        // ter-render (sukses), false kalau tak ada — biar pemanggil fallback.
        async streamReply(text, history) {
            const res = await fetch('/chatbot/stream', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ message: text, history }),
            });
            if (!res.ok || !res.body) return false;

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let acc = '';
            let idx = -1;
            let suggestions = null;

            const applyDelta = (delta) => {
                acc += delta;
                if (idx === -1) {
                    this.messages.push({ role: 'assistant', content: '', display: '', typing: false });
                    idx = this.messages.length - 1;
                }
                // Reassign objek (bukan mutasi ref mentah) supaya Alpine melacak update.
                this.messages[idx] = { ...this.messages[idx], content: acc, display: acc };
                this.$nextTick(() => this.scrollDown());
            };

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });

                let sep;
                while ((sep = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, sep);
                    buffer = buffer.slice(sep + 2);
                    const evt = this.parseSse(raw);
                    if (!evt) continue;
                    if (evt.done) { suggestions = evt.suggestions; continue; }
                    if (evt.delta != null) applyDelta(evt.delta);
                }
            }

            if (idx === -1) return false; // tak ada delta → fallback
            this.applySuggestions(suggestions);
            return true;
        },

        // Parse satu blok event SSE → objek payload (gabung baris data:).
        parseSse(raw) {
            let dataStr = '';
            for (const line of raw.split('\n')) {
                if (line.startsWith('data:')) dataStr += line.slice(5).trimStart();
            }
            if (!dataStr) return null;
            try { return JSON.parse(dataStr); } catch (e) { return null; }
        },

        applySuggestions(suggestions) {
            if (Array.isArray(suggestions) && suggestions.length) {
                this.suggestions = suggestions;
            } else {
                this.shuffleSuggestions();
            }
        },

        // Tampilkan balasan lengkap sekaligus. (Dulu ada efek ketik yang memutасi
        // objek mentah setelah push — Alpine tidak melacak mutasi lewat ref mentah,
        // sehingga hanya kata pertama yang ter-render = balasan tampak "kepotong".)
        pushTyped(fullText) {
            this.messages.push({ role: 'assistant', content: fullText, display: fullText, typing: false });
            this.$nextTick(() => this.scrollDown());
        },

        async copyMessage(m) {
            try {
                await navigator.clipboard.writeText(m.content);
            } catch (e) {
                // senyap — clipboard tidak tersedia
            }
        },

        scrollDown() {
            this.$refs.scrollArea.scrollTop = this.$refs.scrollArea.scrollHeight;
        },

        // Netralkan notasi LaTeX → teks biasa. Model kadang membungkus rumus dalam
        // \[ \frac{}{} \text{} \] yang tak bisa dirender front-end (tampak berantakan).
        // Prompt sudah melarangnya; ini jaring pengaman kalau model tetap bandel.
        deLatex(s) {
            return s
                .replace(/\\\[|\\\]|\\\(|\\\)/g, ' ')
                .replace(/\\frac\s*\{([^{}]*)\}\s*\{([^{}]*)\}/g, '$1 ÷ $2')
                .replace(/\\(?:text|mathbf|mathrm|mathit|bm|boldsymbol|operatorname)\s*\{([^{}]*)\}/g, '$1')
                .replace(/\\times/g, '×').replace(/\\cdot/g, '×').replace(/\\div/g, '÷')
                .replace(/\\approx/g, '≈').replace(/\\%/g, '%').replace(/\\,/g, ' ').replace(/\\ /g, ' ')
                .replace(/\\[a-zA-Z]+/g, '')
                .replace(/[{}]/g, '');
        },

        // Micro-parser markdown → HTML aman. Escape dulu, lalu bangun tag whitelist.
        renderMarkdown(src) {
            if (src == null) return '';
            // Buang LaTeX dulu (cuma kalau ada sinyalnya, biar teks normal tak kesenggol).
            if (/\\\[|\\\(|\\frac|\\text|\\times|\\div|\\mathbf/.test(src)) {
                src = this.deLatex(src);
            }
            // Escape SEMUA karakter HTML-sensitif termasuk kutip — cegah breakout dari
            // atribut href="..." (XSS). Wajib jalan sebelum tag apa pun dibangun.
            const esc = s => s
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
            let text = esc(String(src));

            const lines = text.split('\n');
            let html = '';
            let i = 0;

            // Inline formatting: link (callback agar teks & href benar), code, bold, italic.
            // URL dibatasi http/https + charset aman (tanpa kutip/spasi/angle) sebagai
            // pertahanan berlapis di atas esc() — mencegah breakout atribut sekaligus javascript:.
            const linkFix = s => s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s"'<>)]+)\)/gi,
                (_, t, u) => `<a href="${u}" rel="noopener noreferrer" target="_blank">${t}</a>`);

            const fmt = s => linkFix(s
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/\*([^*]+)\*/g, '<em>$1</em>'));

            while (i < lines.length) {
                let line = lines[i];

                // Tabel markdown: header | --- | baris
                if (/\|/.test(line) && i + 1 < lines.length && /^\s*\|?[\s:|-]+\|?\s*$/.test(lines[i + 1]) && /-/.test(lines[i + 1])) {
                    const parseRow = r => r.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(c => c.trim());
                    const headers = parseRow(line);
                    i += 2;
                    let body = '';
                    while (i < lines.length && /\|/.test(lines[i]) && lines[i].trim() !== '') {
                        const cells = parseRow(lines[i]);
                        body += '<tr>' + cells.map(c => `<td>${fmt(c)}</td>`).join('') + '</tr>';
                        i++;
                    }
                    html += '<table><thead><tr>' + headers.map(h => `<th>${fmt(h)}</th>`).join('') + '</tr></thead><tbody>' + body + '</tbody></table>';
                    continue;
                }

                // List tak berurut
                if (/^\s*[-*]\s+/.test(line)) {
                    let items = '';
                    while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) {
                        items += `<li>${fmt(lines[i].replace(/^\s*[-*]\s+/, ''))}</li>`;
                        i++;
                    }
                    html += `<ul>${items}</ul>`;
                    continue;
                }

                // List berurut
                if (/^\s*\d+\.\s+/.test(line)) {
                    let items = '';
                    while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) {
                        items += `<li>${fmt(lines[i].replace(/^\s*\d+\.\s+/, ''))}</li>`;
                        i++;
                    }
                    html += `<ol>${items}</ol>`;
                    continue;
                }

                // Baris kosong
                if (line.trim() === '') { i++; continue; }

                // Paragraf (gabung baris berurutan non-kosong non-blok)
                let para = line;
                i++;
                while (i < lines.length && lines[i].trim() !== '' && !/^\s*[-*]\s+/.test(lines[i]) && !/^\s*\d+\.\s+/.test(lines[i]) && !/\|/.test(lines[i])) {
                    para += ' ' + lines[i];
                    i++;
                }
                html += `<p>${fmt(para)}</p>`;
            }

            return html;
        },
    };
}
