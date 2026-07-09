function chatbotPage() {
    return {
        conversations: [],
        loadingConversations: true,
        activeId: null,
        loading: false,
        input: '',
        messages: [],
        // Pool pertanyaan — SEMUA di-mapping ke tool ranking/all-time yang jalan tanpa
        // parameter part/bulan spesifik, jadi jawabannya selalu nyambung.
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
        ],
        suggestions: [],

        // Ambil 3 pertanyaan acak dari pool (Fisher–Yates).
        shuffleSuggestions() {
            const pool = [...this.questionPool];
            for (let i = pool.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [pool[i], pool[j]] = [pool[j], pool[i]];
            }
            this.suggestions = pool.slice(0, 3);
        },

        greeting() {
            return { role: 'assistant', content: 'Halo! Saya OPTIMA AI Assistant. Tanya soal MTBF/MTTR mesin & dies, rekap NG, atau perbandingan produksi antar periode.' };
        },

        async init() {
            try {
                const res = await fetch('/chatbot/conversations');
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                this.conversations = await res.json();
            } catch (e) {
                // Silently handle fetch failure; finally will reset loadingConversations
            } finally {
                this.loadingConversations = false;
            }

            if (this.conversations.length > 0) {
                await this.selectConversation(this.conversations[0].id);
            } else {
                this.messages = [this.greeting()];
            }

            this.shuffleSuggestions();
        },

        newChat() {
            this.activeId = null;
            this.messages = [this.greeting()];
            this.input = '';
        },

        askSuggested(question) {
            this.input = question;
            this.send();
        },

        async selectConversation(id) {
            try {
                this.activeId = id;
                const res = await fetch(`/chatbot/conversations/${id}`);
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                this.messages = await res.json();
                this.$nextTick(() => this.scrollDown());
            } catch (e) {
                // Silently handle; leave messages unchanged
            }
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            const isNewConversation = this.activeId === null;

            this.messages.push({ role: 'user', content: text });
            this.input = '';
            this.loading = true;
            this.$nextTick(() => this.scrollDown());

            try {
                const res = await fetch('/chatbot/message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ message: text, conversation_id: this.activeId }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                this.activeId = data.conversation_id;
                this.messages.push({ role: 'assistant', content: data.reply });

                // Saran follow-up kontekstual dari backend; fallback random kalau kosong.
                if (Array.isArray(data.suggestions) && data.suggestions.length) {
                    this.suggestions = data.suggestions;
                } else {
                    this.shuffleSuggestions();
                }

                if (isNewConversation) {
                    this.conversations.unshift({
                        id: data.conversation_id,
                        title: text.length > 40 ? text.slice(0, 40) + '...' : text,
                        updated_at: new Date().toISOString(),
                    });
                }
            } catch (e) {
                this.messages.push({ role: 'assistant', content: 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.' });
                this.shuffleSuggestions();
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollDown());
            }
        },

        scrollDown() {
            this.$refs.scrollArea.scrollTop = this.$refs.scrollArea.scrollHeight;
        },

        async deleteConversation(id) {
            if (!confirm('Hapus percakapan ini?')) return;

            try {
                const res = await fetch(`/chatbot/conversations/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                this.conversations = this.conversations.filter(c => c.id !== id);

                if (this.activeId === id) {
                    this.newChat();
                }
            } catch (e) {
                // Silently handle fetch failure; item stays in the list
            }
        },
    };
}
