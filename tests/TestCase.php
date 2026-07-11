<?php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Provider chatbot di-skip kalau key kosong (guard produksi). Test memakai
        // Http::fake, jadi set key dummy supaya provider yang diuji tidak ter-skip.
        config([
            'services.groq.key'       => 'test-key',
            'services.groq.model'     => 'test-model',
            'services.gemini.key'     => 'test-key',
            'services.gemini.model'   => 'test-model',
            'services.cerebras.key'   => 'test-key',
            'services.cerebras.model' => 'test-model',
            'services.deepseek.key'   => 'test-key',
            'services.deepseek.model' => 'test-model',
        ]);
    }
}
