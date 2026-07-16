<?php
namespace App\Contracts;

/**
 * Satu kapabilitas chatbot yang MENDAFTARKAN DIRI SENDIRI (self-registering).
 *
 * Tujuan arsitektur: nambah fitur/modul baru ke chatbot = bikin SATU class
 * implements ChatCapability lalu tag di service provider. TIDAK perlu edit
 * ChatbotTools, ToolSchemaCatalog, BusinessTableCatalog, atau system prompt.
 *
 * CapabilityRegistry mengumpulkan semua capability yang ter-tag, lalu membangun
 * schema function-calling + system-prompt hint + dispatch map secara otomatis.
 *
 * Satu capability = satu tool/function yang dipapar ke LLM.
 */
interface ChatCapability
{
    /** Nama fungsi yang dipapar ke LLM (harus unik, tanpa spasi). Mis. "getTotalProduksi". */
    public function name(): string;

    /** Deskripsi padat untuk LLM. Dikirim tiap request — hemat kata. */
    public function description(): string;

    /**
     * Blok "parameters" JSON-schema (type/object + properties + required),
     * persis format yang diharapkan provider OpenAI-compatible & Gemini.
     */
    public function parameters(): array;

    /**
     * Kata kunci untuk tool-subsetting: registry bisa kirim HANYA capability
     * yang relevan dengan pertanyaan user (hemat token schema). Kosong = selalu
     * disertakan.
     *
     * @return string[]
     */
    public function keywords(): array;

    /**
     * Role yang boleh memakai capability ini (permission). Kosong = semua role
     * yang punya akses chatbot. Dasar future data-isolation per departemen.
     *
     * @return string[]
     */
    public function roles(): array;

    /**
     * Contoh pertanyaan — dipakai untuk generate suggested-questions & bisa
     * disuntik ke prompt sebagai few-shot. Murni metadata, bukan eksekusi.
     *
     * @return string[]
     */
    public function examples(): array;

    /**
     * Eksekusi tool. $args = argumen yang dikirim LLM (assoc, sudah di-decode).
     * Balikin array hasil (akan di-json_encode ke LLM). Konvensi error tetap
     * ['error' => string] seperti ChatbotTools.
     */
    public function handle(array $args): array;
}
