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
    | Kalau true, schema yang dikirim ke LLM cuma tool yang relevan dengan
    | pertanyaan (hemat token). Registry otomatis fallback ke SEMUA tool kalau
    | tak ada keyword yang cocok, jadi aman. false = selalu kirim semua tool.
    */
    'tool_subsetting' => (bool) env('CHATBOT_TOOL_SUBSETTING', true),

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
