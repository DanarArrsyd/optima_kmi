@extends('layouts.app')
@section('title', 'AI Assistant')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/chatbot.css') }}?v={{ filemtime(public_path('css/chatbot.css')) }}">
@endpush

@section('content')
<div x-data="chatbotPage()" x-init="init()" class="cb-wrap">
    <section class="cb-main">
        <div class="cb-header">
            <div class="cb-header-av"><img src="{{ asset('logo.png') }}" alt="KC" class="cb-logo"></div>
            <div>
                <div class="cb-header-title">OPTIMA AI Assistant</div>
                <div class="cb-header-status"><span class="cb-dot"></span>Online &middot; Sesi tidak disimpan</div>
            </div>
        </div>

        <div class="cb-scroll" x-ref="scrollArea">
            <template x-for="(m, i) in messages" :key="i">
                <div class="cb-row" :class="m.role === 'user' ? 'cb-row-user' : 'cb-row-bot'">
                    <div class="cb-av" :class="m.role === 'user' ? 'cb-av-user' : 'cb-av-bot'">
                        <template x-if="m.role === 'user'"><i class="bi bi-person-fill"></i></template>
                        <template x-if="m.role !== 'user'"><img src="{{ asset('logo.png') }}" alt="KC" class="cb-logo"></template>
                    </div>
                    <div class="cb-bubble-wrap">
                        <div class="cb-bubble" :class="m.role === 'user' ? 'cb-bubble-user' : 'cb-bubble-bot'">
                            <template x-if="m.role === 'user'"><span x-text="m.content"></span></template>
                            <template x-if="m.role !== 'user'"><div class="cb-md" x-html="renderMarkdown(m.display ?? m.content)"></div></template>
                        </div>
                        <div x-show="m.role !== 'user' && !m.typing" class="cb-actions">
                            <button type="button" class="cb-act" @click="copyMessage(m)" title="Salin"><i class="bi bi-clipboard"></i></button>
                            <button type="button" class="cb-act" x-show="i === messages.length - 1" @click="regenerate()" title="Buat ulang jawaban"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                    </div>
                </div>
            </template>
            <div class="cb-row cb-row-bot" x-show="loading">
                <div class="cb-av cb-av-bot"><img src="{{ asset('logo.png') }}" alt="KC" class="cb-logo"></div>
                <div class="cb-bubble cb-bubble-bot cb-typing"><span></span><span></span><span></span></div>
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
