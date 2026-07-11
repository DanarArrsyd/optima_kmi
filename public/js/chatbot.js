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
        // ambil maksimal 20 turn terakhir, hanya field role+content.
        buildHistory() {
            return this.messages
                .slice(1)
                .filter(m => !m.typing)
                .slice(-20)
                .map(m => ({ role: m.role, content: m.content }));
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
            const history = this.buildHistory();
            await this.dispatch(this.lastUserMessage, history);
        },

        async dispatch(text, history) {
            this.loading = true;
            this.$nextTick(() => this.scrollDown());

            try {
                const res = await fetch('/chatbot/message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ message: text, history }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                await this.pushTyped(data.reply);

                if (Array.isArray(data.suggestions) && data.suggestions.length) {
                    this.suggestions = data.suggestions;
                } else {
                    this.shuffleSuggestions();
                }
            } catch (e) {
                this.messages.push({ role: 'assistant', content: 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.', typing: false });
                this.shuffleSuggestions();
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollDown());
            }
        },

        // Efek ketik client-side: reveal per kata, render markdown di akhir.
        async pushTyped(fullText) {
            const msg = { role: 'assistant', content: fullText, display: '', typing: true };
            this.messages.push(msg);
            const words = fullText.split(/(\s+)/);
            for (let i = 0; i < words.length; i++) {
                msg.display += words[i];
                if (i % 2 === 0) {
                    this.$nextTick(() => this.scrollDown());
                    await new Promise(r => setTimeout(r, 18));
                }
            }
            msg.display = fullText;
            msg.typing = false;
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

        // Micro-parser markdown → HTML aman. Escape dulu, lalu bangun tag whitelist.
        renderMarkdown(src) {
            if (src == null) return '';
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
            const linkFix = s => s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s"'<>)]+)\)/g,
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
