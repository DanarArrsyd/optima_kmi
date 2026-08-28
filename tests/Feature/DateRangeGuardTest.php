<?php

namespace Tests\Feature;

use App\Http\Requests\StoreBreakdownDiesRequest;
use App\Http\Requests\StoreBreakdownMesinBatchRequest;
use App\Http\Requests\StoreDandoriBatchRequest;
use App\Http\Requests\StoreMasterMesinRequest;
use App\Http\Requests\StoreMasterPartRequest;
use App\Http\Requests\StoreProduksiBatchRequest;
use App\Http\Requests\StoreQcBatchRequest;
use App\Http\Requests\UpdateBreakdownDiesRequest;
use App\Http\Requests\UpdateBreakdownMesinRequest;
use App\Http\Requests\UpdateProduksiRequest;
use App\Http\Requests\UpdateQcRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rule `date` polos menerima tahun 4-digit apa pun. Salah ketik "2026" → "0026" lolos
 * validasi, MySQL menyimpannya, dan barisnya jatuh dari setiap laporan yang memfilter
 * per rentang tanggal — tanpa error. Tiga baris dandori produksi sudah kena begini
 * (id 24212–24214); lubangnya ada di semua modul, cuma belum kena.
 *
 * Test ini mengunci tiap field tanggal ke rentang yang masuk akal, dan sengaja menguji
 * kedua arah supaya batasnya tak bisa diam-diam dikencengin sampai menolak input sah.
 *
 * Batas atas `tomorrow`, bukan `today`: app timezone UTC (config/app.php), pabrik WIB
 * (UTC+7) — operator input jam 06:00 WIB masih ada di hari UTC sebelumnya.
 */
class DateRangeGuardTest extends TestCase
{
    /**
     * [Request class, field tanggal, tanggal lampau yang sah, context field lain]
     *
     * tanggal_instalasi dapat batas bawah jauh lebih longgar (1990): itu tanggal mesin/dies
     * dipasang, dan mesin stamping umur belasan tahun itu normal di pabrik.
     */
    public static function dateFields(): array
    {
        return [
            'produksi batch' => [StoreProduksiBatchRequest::class,   'rows.*.tanggal_produksi',   '2024-05-10', []],
            'produksi update' => [UpdateProduksiRequest::class,       'tanggal_produksi',          '2024-05-10', []],
            'qc batch cek' => [StoreQcBatchRequest::class,         'rows.*.tgl_pengecekan',     '2024-05-10', []],
            'qc batch part' => [StoreQcBatchRequest::class,         'rows.*.tgl_produksi_part',  '2024-05-10', []],
            'qc update cek' => [UpdateQcRequest::class,             'tgl_pengecekan',            '2024-05-10', []],
            'qc update part' => [UpdateQcRequest::class,             'tgl_produksi_part',         '2024-05-10', []],
            'dandori batch' => [StoreDandoriBatchRequest::class,    'rows.*.tanggal',            '2024-05-10', []],
            'bd mesin batch' => [StoreBreakdownMesinBatchRequest::class,  'rows.*.tanggal_breakdown',         '2024-05-10', []],
            'bd mesin batch mulai' => [StoreBreakdownMesinBatchRequest::class,  'rows.*.tanggal_mulai_perbaikan',   '2024-05-10', ['tanggal_breakdown' => '2024-05-09']],
            'bd mesin batch selesai' => [StoreBreakdownMesinBatchRequest::class,  'rows.*.tanggal_selesai_perbaikan', '2024-05-10', ['tanggal_mulai_perbaikan' => '2024-05-09']],
            'bd mesin update' => [UpdateBreakdownMesinRequest::class, 'tanggal_breakdown',         '2024-05-10', []],
            'bd dies store' => [StoreBreakdownDiesRequest::class,   'tanggal_breakdown',         '2024-05-10', []],
            'bd dies update' => [UpdateBreakdownDiesRequest::class,  'tanggal_breakdown',         '2024-05-10', []],
            'master mesin instalasi' => [StoreMasterMesinRequest::class,     'tanggal_instalasi',         '2015-03-10', []],
            'master part instalasi' => [StoreMasterPartRequest::class,      'tanggal_instalasi',         '2015-03-10', []],
        ];
    }

    /** Validasi hanya field tanggal-nya, pakai rule asli dari Request class. */
    private function validate(string $requestClass, string $field, string $value, array $context): \Illuminate\Validation\Validator
    {
        /** @var FormRequest $request */
        $request = new $requestClass;
        $rules = $request->rules();

        $this->assertArrayHasKey($field, $rules, "{$requestClass} tidak lagi punya field {$field}.");

        /*
         * StoreProduksiBatchRequest menyimpan tanggalnya di dalam baris berulang
         * (rows.*.tanggal_produksi), bukan di kunci datar. Validator butuh nilainya
         * betulan bersarang di rows[0] supaya rule wildcard-nya kepakai — guard yang
         * tak bisa melihat field bersarang akan diam-diam berhenti menjaga field yang
         * justru sedang benar-benar dipakai di produksi.
         */
        if (preg_match('/^(\w+)\.\*\.(\w+)$/', $field, $m)) {
            [, $prefix, $leaf] = $m;

            return Validator::make(
                [$prefix => [array_merge($context, [$leaf => $value])]],
                [$field => $rules[$field]],
            );
        }

        return Validator::make(
            array_merge($context, [$field => $value]),
            [$field => $rules[$field]],
        );
    }

    #[DataProvider('dateFields')]
    public function test_rejects_year_typo(string $requestClass, string $field, string $validPast, array $context): void
    {
        $this->assertTrue(
            $this->validate($requestClass, $field, '0026-06-21', $context)->fails(),
            "{$requestClass}.{$field} masih menerima tahun 0026 — ini bug yang bikin 3 baris dandori hilang dari laporan.",
        );
    }

    #[DataProvider('dateFields')]
    public function test_rejects_absurd_future_year(string $requestClass, string $field, string $validPast, array $context): void
    {
        $this->assertTrue(
            $this->validate($requestClass, $field, '9999-01-01', $context)->fails(),
            "{$requestClass}.{$field} masih menerima tahun 9999.",
        );
    }

    #[DataProvider('dateFields')]
    public function test_still_accepts_a_legitimate_past_date(string $requestClass, string $field, string $validPast, array $context): void
    {
        $this->assertFalse(
            $this->validate($requestClass, $field, $validPast, $context)->fails(),
            "{$requestClass}.{$field} menolak {$validPast} yang sah — batasnya kekencengan.",
        );
    }

    #[DataProvider('dateFields')]
    public function test_still_accepts_tomorrow_for_wib_utc_skew(string $requestClass, string $field, string $validPast, array $context): void
    {
        $this->assertFalse(
            $this->validate($requestClass, $field, now()->addDay()->format('Y-m-d'), $context)->fails(),
            "{$requestClass}.{$field} menolak tanggal besok — operator WIB yang input pagi bisa kena.",
        );
    }
}
