<div x-data="chatbotWidget()" x-init="loadHistory()" style="position:fixed;bottom:20px;right:20px;z-index:1200">
    <button @click="open = !open"
            style="width:52px;height:52px;border-radius:50%;background:#111827;color:#fff;border:none;box-shadow:0 4px 14px rgba(0,0,0,.25);cursor:pointer;font-size:1.3rem">
        <i class="bi" :class="open ? 'bi-x-lg' : 'bi-robot'"></i>
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         style="position:absolute;bottom:64px;right:0;width:360px;max-width:calc(100vw - 40px);height:480px;
                background:#fff;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden">

        <div style="background:#111827;color:#fff;padding:12px 16px;font-weight:700;font-size:0.9rem">
            <i class="bi bi-robot me-2"></i>OPTIMA AI Assistant
        </div>

        <div class="flex-grow-1" style="flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px" x-ref="scrollArea">
            <template x-for="(m, i) in messages" :key="i">
                <div :style="m.role === 'user'
                        ? 'align-self:flex-end;background:#111827;color:#fff;padding:8px 12px;border-radius:10px;max-width:80%;font-size:0.85rem'
                        : 'align-self:flex-start;background:#f3f4f6;color:#111827;padding:8px 12px;border-radius:10px;max-width:80%;font-size:0.85rem'"
                     x-text="m.content"></div>
            </template>
            <div x-show="loading" style="align-self:flex-start;color:#9ca3af;font-size:0.8rem">Mikir...</div>
        </div>

        <form @submit.prevent="send()" style="display:flex;gap:6px;padding:10px;border-top:1px solid #e5e7eb">
            <input type="text" x-model="input" placeholder="Tanya soal data OPTIMA..."
                   :disabled="loading"
                   style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;font-size:0.85rem">
            <button type="submit" :disabled="loading || !input.trim()"
                    style="background:#111827;color:#fff;border:none;border-radius:6px;padding:6px 12px">
                <i class="bi bi-send"></i>
            </button>
        </form>
    </div>
</div>

<script>
function chatbotWidget() {
    return {
        open: false,
        loading: false,
        input: '',
        conversationId: null,
        messages: [],

        async loadHistory() {
            const res = await fetch('{{ route('chatbot.history') }}');
            const data = await res.json();
            this.conversationId = data.conversation_id;
            this.messages = data.messages || [];
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

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
                    body: JSON.stringify({ message: text, conversation_id: this.conversationId }),
                });
                const data = await res.json();
                this.conversationId = data.conversation_id;
                this.messages.push({ role: 'assistant', content: data.reply });
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
    };
}
</script>
