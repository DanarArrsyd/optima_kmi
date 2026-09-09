<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    public function test_staff_repair_terdaftar_di_roles(): void
    {
        $this->assertArrayHasKey('staff_repair', User::ROLES);
        $this->assertSame('Staff Repair', User::ROLES['staff_repair']);
    }
}
