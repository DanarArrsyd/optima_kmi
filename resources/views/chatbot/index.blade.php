@extends('layouts.app')
@section('title', 'AI Assistant')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/chatbot.css') }}?v={{ filemtime(public_path('css/chatbot.css')) }}">
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

<script src="{{ asset('js/chatbot.js') }}?v={{ filemtime(public_path('js/chatbot.js')) }}"></script>
@endsection
