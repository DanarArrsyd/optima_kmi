<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache hasil tool (detik)
    |--------------------------------------------------------------------------
    | Hasil tool chatbot murni read-only & data pabrik berubah per jam, bukan
    | per detik — jadi hasil yang sama (tool + argumen sama) di-cache sebentar
    | supaya pertanyaan beruntun / diulang tak menembak DB berkali-kali.
    | 0 = matikan cache. Default 600s (10 menit): cukup untuk burst, cukup segar.
    */
    'tool_cache_ttl' => (int) env('CHATBOT_TOOL_CACHE_TTL', 600),

    /*
    |--------------------------------------------------------------------------
    | Keyword tool-subsetting
    |--------------------------------------------------------------------------
    | Kalau true, schema yang dikirim ke LLM cuma tool yang keyword-nya cocok
    | dengan pertanyaan (hemat ~1.500 token/request).
    |
    | DEFAULT OFF (2026-07-18). Match keyword berbasis substring frasa TERLALU
    | rapuh: pertanyaan natural "mesin mana yang paling sering breakdown" tak
    | cocok keyword "breakdown mesin" (beda urutan kata), jadi getRankingBreakdown*
    | tak dikirim → LLM ngambang → "data belum ada" padahal datanya ADA. Hemat
    | ~$0.0004/pertanyaan tak sepadan dengan chatbot yang salah jawab. Semua 19
    | tool (~1.972 token) selalu dikirim = LLM selalu punya tool yang tepat.
    |
    | Aktifkan lagi HANYA kalau matching sudah diperbaiki (berbasis kata, bukan
    | frasa) + keyword tiap tool diperluas.
    */
    'tool_subsetting' => (bool) env('CHATBOT_TOOL_SUBSETTING', false),

    /*
    |--------------------------------------------------------------------------
    | Log pemakaian token + cache-hit
    |--------------------------------------------------------------------------
    | Catat token per panggilan LLM (termasuk prompt_cache_hit_tokens dari
    | DeepSeek) ke log level info dengan tag "chatbot.usage". Untuk membuktikan
    | prompt-caching jalan & mengukur hematnya. false = matikan.
    */
    'log_token_usage' => (bool) env('CHATBOT_LOG_TOKEN_USAGE', true),

];
