@extends('layouts.app')
@section('title', 'AI Assistant')
@section('content')
<div x-data="chatbotPage()" x-init="init()" style="display:flex;gap:16px;height:calc(100vh - 130px)">

    {{-- Sidebar --}}
    <div style="width:260px;flex-shrink:0;display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        <div style="padding:12px;border-bottom:1px solid #e5e7eb">
            <button type="button" @click="newChat()"
                    style="width:100%;background:#111827;color:#fff;border:none;border-radius:6px;padding:8px;font-size:0.85rem;font-weight:600">
                <i class="bi bi-plus-lg me-1"></i>Chat Baru
            </button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:6px">
            <template x-if="loadingConversations">
                <div style="padding:12px;color:#9ca3af;font-size:0.8rem">Memuat...</div>
            </template>
            <template x-for="c in conversations" :key="c.id">
                <div :style="'display:flex;align-items:center;gap:4px;border-radius:6px;margin-bottom:2px;background:' + (c.id === activeId ? '#f3f4f6' : 'transparent')">
                    <button type="button" @click="selectConversation(c.id)"
                            style="flex:1;min-width:0;text-align:left;border:none;background:transparent;padding:10px 4px 10px 12px;font-size:0.82rem;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                            x-text="c.title"></button>
                    <button type="button" @click.stop="deleteConversation(c.id)"
                            title="Hapus percakapan"
                            style="flex-shrink:0;border:none;background:transparent;color:#9ca3af;padding:6px 10px 6px 4px">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </template>
        </div>
    </div>

    {{-- Chat panel --}}
    <div style="flex:1;display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;min-width:0">
        <div style="background:#111827;color:#fff;padding:14px 18px;font-weight:700;font-size:0.95rem">
            <i class="bi bi-robot me-2"></i>OPTIMA AI Assistant
        </div>

        <div style="flex:1;min-height:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px" x-ref="scrollArea">
            <template x-for="(m, i) in messages" :key="i">
                <div :style="m.role === 'user'
                        ? 'align-self:flex-end;background:#111827;color:#fff;padding:8px 14px;border-radius:10px;max-width:70%;font-size:0.88rem'
                        : 'align-self:flex-start;background:#f3f4f6;color:#111827;padding:8px 14px;border-radius:10px;max-width:70%;font-size:0.88rem'"
                     x-text="m.content"></div>
            </template>
            <div x-show="loading" style="align-self:flex-start;color:#9ca3af;font-size:0.85rem">Mikir...</div>
        </div>

        <form @submit.prevent="send()" style="display:flex;gap:8px;padding:12px;border-top:1px solid #e5e7eb">
            <input type="text" x-model="input" placeholder="Tanya soal data OPTIMA..."
                   :disabled="loading"
                   style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:0.88rem">
            <button type="submit" :disabled="loading || !input.trim()"
                    style="background:#111827;color:#fff;border:none;border-radius:6px;padding:8px 16px">
                <i class="bi bi-send"></i>
            </button>
        </form>
    </div>
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
        },

        newChat() {
            this.activeId = null;
            this.messages = [this.greeting()];
            this.input = '';
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

                if (isNewConversation) {
                    this.conversations.unshift({
                        id: data.conversation_id,
                        title: text.length > 40 ? text.slice(0, 40) + '...' : text,
                        updated_at: new Date().toISOString(),
                    });
                }
            } catch (e) {
                this.messages.push({ role: 'assistant', content: 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.' });
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
