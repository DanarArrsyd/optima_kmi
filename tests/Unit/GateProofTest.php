<?php
namespace Tests\Unit;

use Tests\TestCase;

/** SEMENTARA — membuktikan gerbang CI bisa merah. Dihapus di commit berikutnya. */
class GateProofTest extends TestCase
{
    public function test_this_must_fail(): void
    {
        $this->assertSame('gate-harus-merah', 'ternyata-hijau');
    }
}
