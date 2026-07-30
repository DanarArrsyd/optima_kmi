function chatbotPage() {
    return {
        loading: false,
        input: '',
        messages: [],
        greeting: '',
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

        /* Mulai KOSONG, bukan dengan sapaan palsu. Sapaan lama dirender sebagai
           pesan asisten sungguhan, lengkap dengan tombol Salin dan "Buat ulang
           jawaban" — membuat ulang teks hardcoded jelas tidak masuk akal.
           Perannya diambil alih keadaan kosong di Blade, yang jadi perabot
           halaman, bukan giliran percakapan.

           ⚠️ Terkait langsung dengan buildHistory(): dulu ada `.slice(1)` untuk
           membuang sapaan itu. Kalau sapaan dikembalikan ke sini, slice itu WAJIB
           dikembalikan juga — kalau tidak, giliran pertama ikut terkirim dua kali. */
        init() {
            this.messages = [];
            this.greeting = this.pickGreeting();
            this.shuffleSuggestions();
        },

        /*
         * Sapaan pembuka, dipilih acak dari pita waktu yang cocok.
         *
         * Jamnya WAJIB dari `new Date()` di BROWSER, bukan dari server: aplikasi
         * jalan di UTC sementara pabrik di WIB (UTC+7). Menghitungnya di PHP
         * membuat pukul 09.00 WIB terbaca 02.00 dan operator disapa "masih
         * tengah malam" saat shift 1 sedang panas.
         *
         * Pita jam mengikuti shift asli pabrik (06-14, 14-22, 22-06), jadi
         * kalimatnya nyambung dengan ritme kerja yang sebenarnya.
         */
        pickGreeting() {
            const jam = new Date().getHours();

            const pita = [
                { sampai: 4,  baris: [
                    'Waktunya sesi tengah malam.',
                    'Shift 3 masih jalan.',
                    'Mesin belum tidur, kita juga belum.',
                    'Sepi di luar, angka tetap bergerak.',
                    'Dini hari. Ada yang mengganjal?',
                ] },
                { sampai: 7,  baris: [
                    'Sebentar lagi ganti shift.',
                    'Subuh, sebelum lini menyala.',
                    'Pagi buta. Mulai dari mana?',
                    'Shift 3 hampir tutup buku.',
                ] },
                { sampai: 11, baris: [
                    'Shift 1 sudah panas.',
                    'Pagi. Mau lihat yang mana?',
                    'Kopi dulu, baru angka.',
                    'Lini sudah jalan sejak tadi.',
                    'Pagi. Ada yang perlu dicek?',
                ] },
                { sampai: 14, baris: [
                    'Jam makan siang, angkanya tetap jalan.',
                    'Rehat dulu, atau lanjut?',
                    'Tengah hari. Sampai mana?',
                    'Shift 1 menuju garis akhir.',
                ] },
                { sampai: 17, baris: [
                    'Shift 2 baru masuk.',
                    'Sore. Cek yang tertinggal?',
                    'Pergantian shift sudah lewat.',
                    'Sore. Mau rekap dulu?',
                ] },
                { sampai: 21, baris: [
                    'Lini mulai melambat.',
                    'Tutup hari, lihat rekapnya?',
                    'Petang. Ada yang belum beres?',
                    'Shift 2 hampir selesai.',
                ] },
                { sampai: 24, baris: [
                    'Shift 3 ambil alih.',
                    'Malam. Masih ada yang mengganjal?',
                    'Lembur, ya?',
                    'Malam. Mau lihat angka hari ini?',
                ] },
            ];

            const baris = (pita.find(p => jam < p.sampai) ?? pita[pita.length - 1]).baris;

            return baris[Math.floor(Math.random() * baris.length)];
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

        // History in-memory yang dikirim ke server: maksimal 12 turn terakhir,
        // hanya field role+content.
        buildHistory() {
            // 12 turn = konteks lebih dari cukup buat chatbot data, tapi hemat token
            // di percakapan panjang (history di-resend tiap request). Clamp konten ke
            // batas server (max:4000) supaya balasan panjang tak bikin request berikut 422.
            //
            // TANPA .slice(1): percakapan tidak lagi dimulai dengan sapaan palsu
            // (lihat init()). Menambahkan slice di sini akan membuang giliran
            // pertama user secara senyap.
            return this.messages
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
            this.renderCharts();
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
            this.renderCharts();
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
            src = String(src);

            // Ekstrak blok ```chart {json}``` → spec, ganti sentinel (jadi <canvas>
            // Chart.js setelah HTML dibangun). Angka diambil model dari hasil tool.
            const charts = [];
            src = src.replace(/```chart\s*\n?([\s\S]*?)```/g, (_, body) => {
                try { charts.push(JSON.parse(body.trim())); return `\n@@CHART${charts.length - 1}@@\n`; }
                catch (e) { return ''; } // JSON rusak → buang, jangan tampil mentah
            });
            // Blok chart belum ketutup (mid-stream) → sembunyikan sampai lengkap.
            src = src.replace(/```chart[\s\S]*$/, '');

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
            let text = esc(src);

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

                /* Heading. Model rutin mengirim '### Judul' dan tanpa penanganan ini
                   tandanya tampil MENTAH di layar. Dibatasi h3–h5 supaya tidak pernah
                   menyaingi judul halaman. */
                const h = line.match(/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/);
                if (h) {
                    /* '###' adalah tingkat yang PALING sering dipakai model untuk
                       judul bagian utama, jadi dipetakan ke h3 — bukan diperkecil
                       mengikuti jumlah pagarnya. */
                    const n = h[1].length;
                    const tag = n <= 3 ? 'h3' : (n === 4 ? 'h4' : 'h5');
                    html += `<${tag}>${fmt(h[2])}</${tag}>`;
                    i++;
                    continue;
                }

                /* Garis horizontal. Harus dicek SETELAH tabel gagal dikenali, karena
                   '---' juga dipakai sebagai baris pemisah header tabel. */
                if (/^\s{0,3}(-{3,}|\*{3,}|_{3,})\s*$/.test(line)
                    && !(i > 0 && /\|/.test(lines[i - 1]))) {
                    html += '<hr>';
                    i++;
                    continue;
                }

                /* Kutipan '> ...', dipakai model untuk catatan kaki.
                   Polanya '&gt;' BUKAN '>': esc() sudah jalan sebelum loop ini,
                   jadi karakternya sudah berubah bentuk. Mencari '>' di sini
                   tidak akan pernah cocok. */
                const KUTIP = /^\s{0,3}&gt;\s?/;
                if (KUTIP.test(line)) {
                    let isi = '';
                    while (i < lines.length && KUTIP.test(lines[i])) {
                        isi += (isi ? ' ' : '') + lines[i].replace(KUTIP, '');
                        i++;
                    }
                    html += `<blockquote>${fmt(isi)}</blockquote>`;
                    continue;
                }

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
                    /* .erp-table = kelas tabel yang dipakai SELURUH halaman lain, jadi
                       jawaban chatbot terbaca sebagai bagian sistem, bukan tempelan.
                       Pembungkus .cb-tablewrap yang menangani scroll horizontal dan
                       melepas tabel dari lebar kolom baca. */
                    html += '<div class="cb-tablewrap"><table class="erp-table"><thead><tr>'
                        + headers.map(h => `<th>${fmt(h)}</th>`).join('')
                        + '</tr></thead><tbody>' + body + '</tbody></table></div>';
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

            // Ganti sentinel dengan wadah canvas (spec di data-spec, di-escape aman).
            html = html.replace(/@@CHART(\d+)@@/g, (_, idx) => {
                const spec = charts[idx];
                if (!spec) return '';
                return `<div class="cb-chart-box" style="position:relative;height:240px;margin:10px 0">`
                     + `<canvas class="cb-chart" data-spec="${esc(JSON.stringify(spec))}"></canvas></div>`;
            });

            return html;
        },

        // Inisialisasi Chart.js untuk canvas hasil renderMarkdown yang belum dirender.
        // Dipanggil setelah bubble bot masuk DOM. Aman kalau Chart/CDN tak tersedia.
        renderCharts() {
            if (typeof Chart === 'undefined' || !this.$refs.scrollArea) return;
            this.$nextTick(() => {
                this.$refs.scrollArea.querySelectorAll('canvas.cb-chart:not([data-done])').forEach(cv => {
                    cv.setAttribute('data-done', '1');
                    let spec;
                    try { spec = JSON.parse(cv.dataset.spec); } catch (e) { return; }
                    try { this.buildChart(cv, spec); } catch (e) { /* jangan patahkan render teks */ }
                });
            });
        },

        // Bangun chart dari spec model: {type:'line|bar', title, labels[], data[], label}.
        // Opsi Chart di-fix di sini; dari model hanya ambil type/labels/data/judul (aman).
        buildChart(canvas, spec) {
            const type = spec.type === 'bar' ? 'bar' : 'line';
            new Chart(canvas.getContext('2d'), {
                type,
                data: {
                    labels: (spec.labels || []).map(String),
                    datasets: [{
                        label: String(spec.label || ''),
                        data: (spec.data || []).map(Number),
                        borderColor: '#111827',
                        backgroundColor: type === 'bar' ? '#374151' : 'rgba(17,24,39,.12)',
                        borderWidth: 2,
                        fill: type === 'line',
                        tension: 0.25,
                        pointRadius: 3,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: !!spec.title, text: String(spec.title || '') },
                        datalabels: { display: false },
                    },
                    scales: { y: { beginAtZero: true } },
                },
            });
        },
    };
}
