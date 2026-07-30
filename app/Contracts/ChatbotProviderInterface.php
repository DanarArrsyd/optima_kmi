<?php

namespace App\Contracts;

interface ChatbotProviderInterface
{
    /**
     * Attempt to answer `$userMessage` using tool-calling against real OPTIMA
     * data. `$history` is an in-memory list of prior turns
     * (['role' => 'user'|'assistant', 'content' => string]) — no persistence.
     * Returns null on total provider failure so the caller can fall back.
     */
    public function ask(array $history, string $userMessage): ?string;

    /**
     * Apakah provider ini punya key & model, tanpa memanggil API-nya.
     *
     * `ask()` mengembalikan null untuk DUA sebab yang sangat berbeda: provider
     * tak dikonfigurasi, atau dikonfigurasi tapi gagal. Tanpa pemisahan ini
     * pemanggil tidak bisa membedakan salah setup dari gangguan, dan pengguna
     * disuruh "coba lagi nanti" untuk masalah yang tidak akan hilang sendiri.
     */
    public function isConfigured(): bool;
}
