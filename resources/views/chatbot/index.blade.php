@extends('layouts.app')
@section('title', 'AI Assistant')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/chatbot.css') }}?v={{ filemtime(public_path('css/chatbot.css')) }}">
@endpush

@section('content')
{{--
    Kanvas penuh, bukan kartu. Header panel sengaja DIHAPUS: topbar sudah
    menyebut "AI Assistant", jadi bar gelap berisi judul yang sama hanya
    memakan ~70px vertikal untuk nol informasi baru. Status sesi pindah ke
    catatan kecil di bawah kolom ketik, dekat tempat orang mengetiknya.
--}}
<div x-data="chatbotPage()" x-init="init()" class="cb-wrap">
    {{--
        Saat kosong: sapaan + kolom ketik + saran jadi SATU kluster di tengah.
        Setelah pesan pertama: percakapan mengisi layar, kolom ketik turun ke bawah.

        Kolom ketiknya TIDAK dipindah di DOM antar dua keadaan — hanya flexbox
        yang berubah lewat kelas .cb-is-empty. Memindahkannya berarti Alpine
        membuat ulang elemennya, dan fokus keyboard user ikut lepas di tengah
        ketikan.
    --}}
    <section class="cb-main" :class="{ 'cb-is-empty': messages.length === 0 }">
        <div class="cb-scroll" x-ref="scrollArea">
            <div class="cb-thread">

                <div class="cb-empty" x-show="messages.length === 0" x-cloak>
                    {{-- Sapaan dipilih di browser dari jam LOKAL user. Menghitungnya
                         di server salah: app UTC, pabrik WIB. --}}
                    <h1 class="cb-greet" x-text="greeting"></h1>
                </div>

                <template x-for="(m, i) in messages" :key="i">
                    <div class="cb-row" :class="m.role === 'user' ? 'cb-row-user' : 'cb-row-bot'">
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
                    <div class="cb-bubble cb-bubble-bot cb-typing"><span></span><span></span><span></span></div>
                </div>

            </div>
        </div>

        <div class="cb-foot">
            <div class="cb-foot-inner">
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

                <div class="cb-note"><span class="cb-dot"></span>Online &middot; Sesi tidak disimpan</div>
            </div>
        </div>
    </section>
</div>

<script src="{{ asset('js/chatbot.js') }}?v={{ filemtime(public_path('js/chatbot.js')) }}"></script>
@endsection
