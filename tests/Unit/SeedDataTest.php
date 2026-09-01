<?php

namespace Tests\Unit;

use App\Pos\Sheets\SeedData;
use PHPUnit\Framework\TestCase;

class SeedDataTest extends TestCase
{
    public function test_seed_has_expected_counts(): void
    {
        $sheets = SeedData::sheets();
        $this->assertCount(8, $sheets['MenuItems']['rows']);
        $this->assertCount(5, $sheets['Categories']['rows']);
        $this->assertCount(12, $sheets['Tables']['rows']);
        $this->assertCount(4, $sheets['Staff']['rows']);
        // Promotions row: Code column (index 1) = WELCOME10
        $this->assertSame('WELCOME10', $sheets['Promotions']['rows'][0][1]);
    }

    public function test_all_14_sheets_present(): void
    {
        $sheets = SeedData::sheets();
        foreach ([
            'Tables', 'Categories', 'MenuItems', 'Options', 'AddOns', 'Promotions',
            'OrderSessions', 'OrderItems', 'CallLogs', 'Payments', 'Transactions',
            'Staff', 'Settings', 'AuditLog',
        ] as $name) {
            $this->assertArrayHasKey($name, $sheets, "missing sheet $name");
            $this->assertNotEmpty($sheets[$name]['headers'], "$name has no headers");
        }
    }
}
