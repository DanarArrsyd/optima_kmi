<?php
namespace App\Services\Chatbot;

/**
 * Validasi & sanitasi SQL untuk tool queryDatabase (read-only, whitelist tabel).
 * Dipisah dari ChatbotTools supaya boundary keamanan bisa diuji terisolasi.
 *
 * sanitize() TIDAK mengeksekusi query — hanya mengembalikan:
 *   - ['error' => string]  bila ditolak, atau
 *   - ['sql'   => string]  SQL yang sudah di-clamp LIMIT, siap dieksekusi read-only.
 */
class SqlGuard
{
    /** Batas baris hasil queryDatabase sebelum ditandai truncated. Kecil sengaja —
     *  200 baris mentah membengkakkan token per request sampai kena rate limit provider. */
    public const ROW_CAP = 25;

    public function __construct(private BusinessTableCatalog $catalog = new BusinessTableCatalog()) {}

    /**
     * @return array{error?: string, sql?: string}
     */
    public function sanitize(string $sql): array
    {
        // 0. Buang komentar DULU supaya tak bisa dipakai memisah token atau menyembunyikan tabel.
        $stripped = preg_replace('/\/\*.*?\*\//s', ' ', trim($sql));
        $stripped = preg_replace('/--[^\n]*/', ' ', $stripped);
        $stripped = trim(preg_replace('/#[^\n]*/', ' ', $stripped));

        // 1. Harus SELECT/WITH.
        if (!preg_match('/^(select|with)\b/i', $stripped)) {
            return ['error' => 'Hanya query baca (SELECT) yang diizinkan.'];
        }

        // 2. Tolak multi-statement (titik-koma selain trailing tunggal).
        if (str_contains(rtrim($stripped, "; \n\t"), ';')) {
            return ['error' => 'Hanya satu perintah query yang diizinkan.'];
        }

        // 3. Tolak keyword tulis/DDL sebagai kata utuh.
        if (preg_match('/\b(insert|update|delete|replace|drop|alter|truncate|create|grant|revoke|attach|pragma|into|lock|call|exec|merge)\b/i', $stripped)) {
            return ['error' => 'Query mengandung operasi yang tidak diizinkan.'];
        }

        // 3b. Tolak fungsi baca-file / bocor-metadata dan akses system-variable — allowlist
        //     tabel bukan sandbox penuh. Bentuk fungsi `fn(` + `@@` untuk system var.
        if (preg_match('/\b(load_file|user|current_user|system_user|session_user|database|schema|version)\s*\(|@@/i', $stripped)) {
            return ['error' => 'Query mengandung fungsi yang tidak diizinkan.'];
        }

        // 3c. MySQL menerima CURRENT_USER/SESSION_USER/SYSTEM_USER TANPA tanda kurung, jadi
        //     penjaga `fn(` di atas melewatkannya. Tak ada kolom bisnis bernama begini,
        //     jadi menolaknya sebagai kata utuh aman.
        if (preg_match('/\b(current_user|session_user|system_user)\b/i', $stripped)) {
            return ['error' => 'Query mengandung fungsi yang tidak diizinkan.'];
        }

        // 4. Blacklist keras: nama tabel sistem / skema metadata tak boleh muncul sama sekali,
        //    apa pun cara referensinya (identifier bersifat atomik — tak bisa dipecah komentar).
        if (preg_match('/\b(users|sessions|jobs|job_batches|failed_jobs|password_reset_tokens|cache|cache_locks|activity_log|app_notifications|chat_conversations|chat_messages|migrations|information_schema|performance_schema|mysql|sys|sqlite_master|sqlite_sequence|sqlite_schema)\b/i', $stripped)) {
            return ['error' => 'Query menyentuh tabel yang tidak tersedia untuk ditelusuri.'];
        }

        // 5. Whitelist positif: tiap tabel setelah FROM/JOIN (termasuk daftar dipisah koma) wajib bisnis.
        //
        //    Backtick dibuang DULU. Itu pembungkus identifier MySQL, dan regex di bawah cuma
        //    cocok dengan nama telanjang — tanpa langkah ini `JOIN `x`` tak terbaca sebagai
        //    tabel sama sekali, jadi lolos tanpa pernah dicek (asal ada satu FROM telanjang
        //    yang sah), sementara `FROM `master_mesin`` yang benar malah ditolak karena
        //    dianggap tak menyebut tabel. Whitelist ini lapis kedua setelah blacklist; kalau
        //    ia buta, keamanan bergantung penuh pada kelengkapan blacklist.
        //
        //    Hanya backtick yang dibuang, bukan tanda kutip: di MySQL default `"` adalah
        //    pembatas string, jadi membuangnya bisa mengubah literal jadi identifier semu.
        $forTables = str_replace('`', '', $stripped);

        preg_match_all('/\b(?:from|join)\s+([a-zA-Z_][a-zA-Z0-9_]*(?:\s*,\s*[a-zA-Z_][a-zA-Z0-9_]*)*)/i', $forTables, $mm);
        $referenced = [];
        foreach ($mm[1] as $clause) {
            foreach (preg_split('/\s*,\s*/', $clause) as $tbl) {
                $referenced[] = strtolower(trim($tbl));
            }
        }
        if (empty($referenced)) {
            return ['error' => 'Query harus menyebut tabel bisnis yang valid.'];
        }
        foreach ($referenced as $tbl) {
            if (!$this->catalog->has($tbl)) {
                return ['error' => "Tabel \"{$tbl}\" tidak tersedia untuk ditelusuri."];
            }
        }

        // 6. Clamp SEMUA angka LIMIT ke cap (bentuk `n` dan `offset, count`), lalu pastikan
        //    ada LIMIT top-level (append kalau query tak diakhiri LIMIT — mis. LIMIT cuma di subquery).
        $capped = preg_replace_callback(
            '/\blimit\s+(\d+)\s*,\s*(\d+)/i',
            fn ($x) => 'LIMIT ' . $x[1] . ', ' . min((int) $x[2], self::ROW_CAP),
            $stripped
        );
        $capped = preg_replace_callback(
            '/\blimit\s+(\d+)(?!\s*,)/i',
            fn ($x) => 'LIMIT ' . min((int) $x[1], self::ROW_CAP),
            $capped
        );
        if (!preg_match('/\blimit\s+\d+(\s*,\s*\d+)?\s*;?\s*$/i', $capped)) {
            $capped = rtrim($capped, "; \n\t") . ' LIMIT ' . self::ROW_CAP;
        }

        return ['sql' => $capped];
    }
}
