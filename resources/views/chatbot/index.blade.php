@extends('layouts.app')
@section('title', 'AI Assistant')

@push('styles')
<style>
/* ══════════════════════════════════════════════════════════════
   OPTIMA AI Assistant — Chat surface (ERP monochrome premium)
   Note: layout kills all box-shadow globally → depth via borders
         & layered surfaces only.
════════════════════════════════════════════════════════════════ */
.cb-wrap { display:flex; gap:14px; height:calc(100vh - 108px); }

/* ── Scrollbars (thin, monochrome) ── */
.cb-scroll::-webkit-scrollbar, .cb-list::-webkit-scrollbar { width:6px; }
.cb-scroll::-webkit-scrollbar-track, .cb-list::-webkit-scrollbar-track { background:transparent; }
.cb-scroll::-webkit-scrollbar-thumb, .cb-list::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }
.cb-scroll::-webkit-scrollbar-thumb:hover, .cb-list::-webkit-scrollbar-thumb:hover { background:#9ca3af; }

/* ══ Sidebar ══════════════════════════════════════════════════ */
.cb-side { width:266px; flex-shrink:0; display:flex; flex-direction:column;
           background:var(--surface); border:1px solid var(--border); border-radius:3px; overflow:hidden; }
.cb-side-head { padding:12px; border-bottom:1px solid var(--border-2); }
.cb-newchat { width:100%; background:#111827; color:#fff; border:none; border-radius:3px;
              padding:10px; font-size:0.84rem; font-weight:700; letter-spacing:.3px; cursor:pointer;
              display:flex; align-items:center; justify-content:center; gap:8px; transition:background .16s ease; }
.cb-newchat:hover { background:#374151; }
.cb-side-label { font-size:11px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase;
                 color:#9ca3af; padding:12px 14px 5px; }
.cb-list { flex:1; overflow-y:auto; padding:2px 6px 8px; }
.cb-conv { display:flex; align-items:center; border-radius:3px; margin-bottom:2px;
           border-left:2px solid transparent; transition:background .12s ease; }
.cb-conv:hover { background:#f3f4f6; }
.cb-conv.active { background:#f3f4f6; border-left-color:#111827; }
.cb-conv-btn { flex:1; min-width:0; text-align:left; border:none; background:transparent;
               padding:10px 4px 10px 10px; font-size:0.82rem; color:#111827; cursor:pointer;
               display:flex; align-items:center; gap:9px; }
.cb-conv-btn i { color:#9ca3af; font-size:13px; flex-shrink:0; }
.cb-conv-btn span { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cb-conv-del { flex-shrink:0; border:none; background:transparent; color:#c4c9d1;
               padding:6px 10px; cursor:pointer; opacity:0; transition:opacity .12s ease, color .12s ease; }
.cb-conv:hover .cb-conv-del { opacity:1; }
.cb-conv-del:hover { color:#b91c1c; }
.cb-side-empty { padding:16px 14px; color:#9ca3af; font-size:0.78rem; text-align:center; line-height:1.6; }

/* ══ Chat panel ═══════════════════════════════════════════════ */
.cb-main { flex:1; display:flex; flex-direction:column; background:var(--surface);
           border:1px solid var(--border); border-radius:3px; overflow:hidden; min-width:0; }

/* Header */
.cb-header { display:flex; align-items:center; gap:12px; padding:12px 18px; background:#111827; color:#fff; }
.cb-header-av { width:36px; height:36px; border-radius:50%; background:#374151; border:1px solid #4b5563;
                display:flex; align-items:center; justify-content:center; font-size:17px; color:#fff; flex-shrink:0; }
.cb-header-title { font-size:0.95rem; font-weight:700; line-height:1.2; }
.cb-header-status { font-size:0.72rem; color:#9ca3af; font-weight:600; letter-spacing:.3px;
                    display:flex; align-items:center; gap:6px; margin-top:3px; }
.cb-dot { width:7px; height:7px; border-radius:50%; background:#22c55e; animation:cbPulse 2s ease-in-out infinite; }
@keyframes cbPulse { 0%,100% { opacity:1; } 50% { opacity:.35; } }

/* Scroll area */
.cb-scroll { flex:1; min-height:0; overflow-y:auto; padding:22px 20px;
             display:flex; flex-direction:column; gap:16px; background:#fbfbfc; }

/* Message row */
.cb-row { display:flex; gap:10px; max-width:82%; align-items:flex-start; animation:cbIn .18s ease; }
.cb-row-bot { align-self:flex-start; }
.cb-row-user { align-self:flex-end; flex-direction:row-reverse; }
@keyframes cbIn { from { opacity:0; transform:translateY(5px); } to { opacity:1; transform:none; } }

.cb-av { width:30px; height:30px; border-radius:50%; flex-shrink:0;
         display:flex; align-items:center; justify-content:center; font-size:14px; }
.cb-av-bot { background:#111827; color:#fff; }
.cb-av-user { background:#e5e7eb; color:#4b5563; border:1px solid #d1d5db; }

.cb-bubble { padding:10px 14px; font-size:0.9rem; line-height:1.55;
             white-space:pre-wrap; word-break:break-word; border-radius:12px; }
.cb-bubble-bot { background:#fff; border:1px solid #e5e7eb; color:#111827; border-top-left-radius:3px; }
.cb-bubble-user { background:#111827; color:#fff; border-top-right-radius:3px; }

/* Typing indicator */
.cb-typing { display:flex; gap:5px; align-items:center; padding:13px 14px; }
.cb-typing span { width:6px; height:6px; border-radius:50%; background:#9ca3af; animation:cbBlink 1.2s infinite ease-in-out; }
.cb-typing span:nth-child(2) { animation-delay:.18s; }
.cb-typing span:nth-child(3) { animation-delay:.36s; }
@keyframes cbBlink { 0%,80%,100% { opacity:.25; transform:translateY(0); } 40% { opacity:1; transform:translateY(-3px); } }

/* Suggested chips */
.cb-chips { display:flex; align-items:center; flex-wrap:wrap; gap:8px; padding:2px 20px 14px; background:#fbfbfc; }
.cb-chip { border:1px solid #d1d5db; background:#fff; color:#374151; border-radius:18px;
           padding:7px 14px; font-size:0.8rem; cursor:pointer; transition:all .14s ease;
           display:flex; align-items:center; gap:7px; white-space:nowrap; }
.cb-chip i { font-size:12px; color:#9ca3af; transition:color .14s ease; }
.cb-chip:hover { background:#111827; color:#fff; border-color:#111827; }
.cb-chip:hover i { color:#fff; }
.cb-shuffle { flex-shrink:0; width:32px; height:32px; border:1px solid #d1d5db; background:#fff;
              color:#6b7280; border-radius:50%; cursor:pointer; display:flex; align-items:center;
              justify-content:center; font-size:13px; transition:all .14s ease; }
.cb-shuffle:hover { background:#111827; color:#fff; border-color:#111827; }
@media (max-width:767px) {
    .cb-chips { flex-wrap:nowrap; overflow-x:auto; padding-bottom:10px; }
    .cb-chips::-webkit-scrollbar { height:0; }
}

/* Input bar */
.cb-form { display:flex; gap:10px; padding:14px 16px; border-top:1px solid var(--border-2);
           background:var(--surface); align-items:center; }
.cb-input { flex:1; border:1px solid #d1d5db; border-radius:22px; padding:11px 17px;
            font-size:0.9rem; color:#111827; background:#f9fafb; transition:border-color .16s ease, background .16s ease; }
.cb-input:focus { outline:none; border-color:#111827; background:#fff; }
.cb-input:disabled { background:#f3f4f6; color:#9ca3af; }
.cb-send { width:42px; height:42px; flex-shrink:0; background:#111827; color:#fff; border:none;
           border-radius:50%; display:flex; align-items:center; justify-content:center;
           font-size:15px; cursor:pointer; transition:background .16s ease; }
.cb-send:hover:not(:disabled) { background:#374151; }
.cb-send:disabled { background:#d1d5db; cursor:not-allowed; }

/* Mobile */
@media (max-width:767px) {
    .cb-wrap { height:calc(100vh - 92px); }
    .cb-side { display:none; }
    .cb-row { max-width:92%; }
}
</style>
@endpush

@section('content')
<div x-data="chatbotPage()" x-init="init()" class="cb-wrap">

    {{-- ══ Sidebar ══ --}}
    <aside class="cb-side">
        <div class="cb-side-head">
            <button type="button" @click="newChat()" class="cb-newchat">
                <i class="bi bi-plus-lg"></i>Chat Baru
            </button>
        </div>
        <div class="cb-side-label">Riwayat Percakapan</div>
        <div class="cb-list">
            <template x-if="loadingConversations">
                <div class="cb-side-empty">Memuat&hellip;</div>
            </template>
            <template x-if="!loadingConversations && conversations.length === 0">
                <div class="cb-side-empty">Belum ada percakapan.<br>Mulai chat baru untuk bertanya.</div>
            </template>
            <template x-for="c in conversations" :key="c.id">
                <div class="cb-conv" :class="{ 'active': c.id === activeId }">
                    <button type="button" @click="selectConversation(c.id)" class="cb-conv-btn">
                        <i class="bi bi-chat-left-text"></i>
                        <span x-text="c.title"></span>
                    </button>
                    <button type="button" @click.stop="deleteConversation(c.id)"
                            title="Hapus percakapan" class="cb-conv-del">
                        <i class="bi bi-trash3"></i>
                    </button>
                </div>
            </template>
        </div>
    </aside>

    {{-- ══ Chat panel ══ --}}
    <section class="cb-main">
        <div class="cb-header">
            <div class="cb-header-av"><i class="bi bi-robot"></i></div>
            <div>
                <div class="cb-header-title">OPTIMA AI Assistant</div>
                <div class="cb-header-status"><span class="cb-dot"></span>Online &middot; Siap membantu</div>
            </div>
        </div>

        <div class="cb-scroll" x-ref="scrollArea">
            <template x-for="(m, i) in messages" :key="i">
                <div class="cb-row" :class="m.role === 'user' ? 'cb-row-user' : 'cb-row-bot'">
                    <div class="cb-av" :class="m.role === 'user' ? 'cb-av-user' : 'cb-av-bot'">
                        <i :class="m.role === 'user' ? 'bi bi-person-fill' : 'bi bi-robot'"></i>
                    </div>
                    <div class="cb-bubble" :class="m.role === 'user' ? 'cb-bubble-user' : 'cb-bubble-bot'"
                         x-text="m.content"></div>
                </div>
            </template>
            <div class="cb-row cb-row-bot" x-show="loading">
                <div class="cb-av cb-av-bot"><i class="bi bi-robot"></i></div>
                <div class="cb-bubble cb-bubble-bot cb-typing">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>

        <div x-show="!loading" class="cb-chips">
            <button type="button" @click="shuffleSuggestions()" class="cb-shuffle" title="Ganti pertanyaan">
                <i class="bi bi-shuffle"></i>
            </button>
            <template x-for="q in suggestions" :key="q">
                <button type="button" @click="askSuggested(q)" class="cb-chip">
                    <i class="bi bi-lightning-charge-fill"></i><span x-text="q"></span>
                </button>
            </template>
        </div>

        <form @submit.prevent="send()" class="cb-form">
            <input type="text" x-model="input" placeholder="Tanya soal data OPTIMA&hellip;"
                   :disabled="loading" class="cb-input">
            <button type="submit" :disabled="loading || !input.trim()" class="cb-send" title="Kirim">
                <i class="bi bi-send-fill"></i>
            </button>
        </form>
    </section>
</div>

<script>
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
                const res = await fetch('{{ route('chatbot.conversations') }}');
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
                const res = await fetch('{{ route('chatbot.send-message') }}', {
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
</script>
@endsection
