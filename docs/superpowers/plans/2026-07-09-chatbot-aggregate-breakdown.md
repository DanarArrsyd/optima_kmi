# AI Chatbot — Aggregate Breakdown Tools + Refusal Guardrail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix an observed production hallucination — a broad, multi-month, all-dies question ("berapa breakdown dies selama 2026") has no matching tool among the 4 existing ones, and the model answered anyway with invented numbers instead of refusing. Add 2 aggregate tools that cover this query shape for real, and harden the system prompt so any question still outside tool coverage gets an honest refusal.

**Architecture:** Two new `ChatbotTools` methods reuse the existing `MaintenanceDiesDashboardService`/`MaintenanceDashboardService::compute()` calls in their already-supported "no ID = all dies/machines" mode, summing `count` across a caller-supplied month range. The system prompt (currently duplicated by hand, identically, in both `GeminiChatbotService` and `GroqChatbotService`) moves into one shared trait so the two can no longer drift apart, and gains one explicit refusal instruction.

**Tech Stack:** Laravel 13 Eloquent, PHPUnit (`php artisan test`).

## Global Constraints

- Both new tools reuse existing dashboard `compute()` methods — no new raw queries
- Tool parameters stay `YYYY-MM` per month; the new tools take `bulanMulai`/`bulanAkhir` defining an inclusive range
- System prompt text must be identical between `GeminiChatbotService` and `GroqChatbotService` — enforced structurally via a shared trait, not by hand-copying
- No changes to either service's request/response handling logic — this work only touches `ChatbotTools` and the shared system prompt

Spec: [docs/superpowers/specs/2026-07-09-chatbot-aggregate-breakdown-design.md](../specs/2026-07-09-chatbot-aggregate-breakdown-design.md)

---

## Task 1: Aggregate breakdown tools

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Produces: `ChatbotTools::getTotalBreakdownDies(string $bulanMulai, string $bulanAkhir): array`, `ChatbotTools::getTotalBreakdownMesin(string $bulanMulai, string $bulanAkhir): array` — both dispatchable by name from either provider service's tool-calling loop (same `method_exists($this->tools, $name)` + `$this->tools->{$name}(...$args)` pattern already used for the 4 existing tools)
- Produces: 2 new entries appended to `ChatbotTools::schemaDefinitions()`, consumed by both `GeminiChatbotService::callGemini()` and `GroqChatbotService::toolSchema()` automatically (no changes needed in either service — both already read the full array from `schemaDefinitions()`)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php` (add `App\Models\MasterMesin`, `App\Models\MasterPart`, `App\Models\MasterPartProses`, `App\Models\TransaksiBreakdownDies`, `App\Models\TransaksiBreakdownMesin` are already imported — no new `use` statements needed):

```php
    public function test_get_total_breakdown_dies_sums_across_month_range(): void
    {
        $part1 = MasterPart::create(['part_no' => 'PT-D1', 'part_name' => 'A', 'customer' => 'Toyota']);
        $proses1 = MasterPartProses::create([
            'id_part' => $part1->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $part2 = MasterPart::create(['part_no' => 'PT-D2', 'part_name' => 'B', 'customer' => 'Honda']);
        $proses2 = MasterPartProses::create([
            'id_part' => $part2->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-A', 'id_part_proses' => $proses1->id,
            'tanggal_breakdown' => '2026-06-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-06-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-06-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-B', 'id_part_proses' => $proses2->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTotalBreakdownDies('2026-06', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_breakdown']);
        $this->assertSame(2, $result['jumlah_dies_terdampak']);
    }

    public function test_get_total_breakdown_dies_returns_error_for_invalid_month_format(): void
    {
        $result = (new ChatbotTools())->getTotalBreakdownDies('bulan-salah', '2026-07');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_total_breakdown_dies_returns_error_when_start_after_end(): void
    {
        $result = (new ChatbotTools())->getTotalBreakdownDies('2026-07', '2026-06');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_total_breakdown_mesin_sums_across_month_range(): void
    {
        $mesin1 = MasterMesin::create([
            'id_mesin' => 'M-X1', 'nama_mesin' => 'Press X1', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesin2 = MasterMesin::create([
            'id_mesin' => 'M-X2', 'nama_mesin' => 'Press X2', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-A', 'id_mesin' => $mesin1->id,
            'tanggal_breakdown' => '2026-06-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-06-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-06-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-B', 'id_mesin' => $mesin2->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTotalBreakdownMesin('2026-06', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_breakdown']);
        $this->assertSame(2, $result['jumlah_mesin_terdampak']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: FAIL — `Call to undefined method App\Services\ChatbotTools::getTotalBreakdownDies()` (and similarly for `getTotalBreakdownMesin`).

- [ ] **Step 3: Add the `monthSpan` helper**

In `app/Services/ChatbotTools.php`, add this private method right after `monthRange()`:

```php
    private function monthSpan(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, ] = $this->monthRange($bulanMulai);
        [, $to]   = $this->monthRange($bulanAkhir);

        if (!$from || !$to) {
            return [null, null, 'Format bulan tidak valid, gunakan format YYYY-MM.'];
        }
        if ($from->gt($to)) {
            return [null, null, 'Bulan mulai tidak boleh setelah bulan akhir.'];
        }

        return [$from, $to, null];
    }
```

- [ ] **Step 4: Add the two tool methods**

In `app/Services/ChatbotTools.php`, add these two public methods right after `bandingkanProduksi()` (before the private `produksiSummary()`/`monthRange()` helpers):

```php
    /** Total kejadian breakdown untuk SEMUA dies dalam rentang bulan. */
    public function getTotalBreakdownDies(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = $this->diesDashboard->compute($from, $to);

        return [
            'bulan_mulai'           => $bulanMulai,
            'bulan_akhir'           => $bulanAkhir,
            'total_breakdown'       => array_sum(array_column($rows, 'count')),
            'jumlah_dies_terdampak' => count(array_filter($rows, fn ($r) => $r['count'] > 0)),
        ];
    }

    /** Total kejadian breakdown untuk SEMUA mesin dalam rentang bulan. */
    public function getTotalBreakdownMesin(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = $this->mesinDashboard->compute($from, $to);

        return [
            'bulan_mulai'            => $bulanMulai,
            'bulan_akhir'            => $bulanAkhir,
            'total_breakdown'        => array_sum(array_column($rows, 'count')),
            'jumlah_mesin_terdampak' => count(array_filter($rows, fn ($r) => $r['count'] > 0)),
        ];
    }
```

- [ ] **Step 5: Add the schema entries**

In `app/Services/ChatbotTools.php`, inside `schemaDefinitions()`, add these two entries to the returned array, after the existing `bandingkanProduksi` entry (before the closing `];`):

```php
            [
                'name' => 'getTotalBreakdownDies',
                'description' => 'Total kejadian breakdown untuk SEMUA dies dalam rentang bulan tertentu (dipakai untuk pertanyaan luas/agregat, bukan per part spesifik).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
            [
                'name' => 'getTotalBreakdownMesin',
                'description' => 'Total kejadian breakdown untuk SEMUA mesin dalam rentang bulan tertentu (dipakai untuk pertanyaan luas/agregat, bukan per mesin spesifik).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS (all tests in the file, including the 4 new ones)

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add aggregate breakdown tools for dies and mesin"
```

---

## Task 2: Shared system prompt + explicit refusal instruction

**Files:**
- Create: `app/Services/Concerns/HasChatbotSystemPrompt.php`
- Modify: `app/Services/GeminiChatbotService.php`
- Modify: `app/Services/GroqChatbotService.php`

**Interfaces:**
- Produces: `HasChatbotSystemPrompt::SYSTEM_PROMPT` (a `protected const`), consumed by both `GeminiChatbotService` and `GroqChatbotService` via `use HasChatbotSystemPrompt;` — each references it as `self::SYSTEM_PROMPT`, unchanged call-site syntax

This task has no new automated test: the trait extraction makes prompt drift between the two services structurally impossible (one definition, not two to keep in sync) rather than something to assert in a test. The existing `GeminiChatbotServiceTest` and `GroqChatbotServiceTest` suites don't inspect the prompt text directly — they test tool-calling behavior — so they serve as the regression check that nothing else broke.

- [ ] **Step 1: Create the trait**

Create `app/Services/Concerns/HasChatbotSystemPrompt.php`:

```php
<?php
namespace App\Services\Concerns;

trait HasChatbotSystemPrompt
{
    protected const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tidak ada tool yang cocok untuk menjawab pertanyaan user, WAJIB bilang terus terang bahwa kamu tidak punya kapabilitas untuk itu — JANGAN membuat jawaban sendiri meskipun terdengar masuk akal.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;
}
```

- [ ] **Step 2: Wire `GeminiChatbotService` to the trait**

In `app/Services/GeminiChatbotService.php`:
1. Add `use App\Services\Concerns\HasChatbotSystemPrompt;` to the `use` imports (after `use App\Contracts\ChatbotProviderInterface;`)
2. Add `use HasChatbotSystemPrompt;` as the first line inside the class body (before `private const MAX_ITERATIONS = 5;`)
3. Delete the class's own `private const SYSTEM_PROMPT = <<<TEXT ... TEXT;` block entirely (lines 13-18 in the current file) — the trait now provides it

The result should read:

```php
<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use App\Services\Concerns\HasChatbotSystemPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiChatbotService implements ChatbotProviderInterface
{
    use HasChatbotSystemPrompt;

    private const MAX_ITERATIONS = 5;

    public function __construct(private ChatbotTools $tools) {}

    // ...rest of the class unchanged (ask(), buildHistory(), callGemini())...
}
```

Every other line in the file (the `ask()`, `buildHistory()`, `callGemini()` methods) stays exactly as it is — `self::SYSTEM_PROMPT` inside `callGemini()` now resolves through the trait instead of the local constant, with no call-site change needed.

- [ ] **Step 3: Wire `GroqChatbotService` to the trait**

Same change as Step 2, applied to `app/Services/GroqChatbotService.php`: add the `use App\Services\Concerns\HasChatbotSystemPrompt;` import, add `use HasChatbotSystemPrompt;` as the first line in the class body, delete the class's own `private const SYSTEM_PROMPT` block. Every other method (`ask()`, `buildHistory()`, `callGroq()`, `toolSchema()`) stays unchanged.

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test`
Expected: PASS — `GeminiChatbotServiceTest` and `GroqChatbotServiceTest` don't assert on prompt text, so this run only confirms the trait wiring didn't break tool-calling behavior in either service.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Concerns/HasChatbotSystemPrompt.php app/Services/GeminiChatbotService.php app/Services/GroqChatbotService.php
git commit -m "refactor(chatbot): share system prompt via trait, add explicit refusal instruction"
```

---

## Task 3: Static verification

Not a code-change task — confirms the feature is internally consistent before calling this done.

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: all tests passing, including the 4 new `ChatbotToolsTest` cases from Task 1.

- [ ] **Step 2: Blade/config compile check**

Run: `php artisan view:cache` then `php artisan view:clear` (always run `view:clear` afterward, even on success)
Expected: `Blade templates cached successfully`, then `Compiled views cleared successfully` — this task didn't touch any views, but it's a cheap sanity check that nothing else in the tree is in a broken state.

- [ ] **Step 3: Report the manual hosting deploy checklist to the user**

Documentation only, not part of git history:

**Files to upload via FTP:**
- New: `app/Services/Concerns/HasChatbotSystemPrompt.php`
- Modified: `app/Services/ChatbotTools.php`
- Modified: `app/Services/GeminiChatbotService.php`
- Modified: `app/Services/GroqChatbotService.php`

No `.env`/config changes, no migrations. After uploading, open `public/diag.php` once (clears `bootstrap/cache/*.php`, needed for any PHP class change to take effect given OPcache on the host). Test with a question the new tools cover (e.g. "berapa breakdown dies selama 2026" or "breakdown mesin bulan Juni sampai Juli berapa") and confirm the reply cites real numbers, not the kind of fabricated answer that prompted this work.
