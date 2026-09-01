<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function App\Pos\Support\boolish;
use function App\Pos\Support\money;
use function App\Pos\Support\normalizeText;
use function App\Pos\Support\sha256;
use function App\Pos\Support\uuidPrefixed;

class HelpersTest extends TestCase
{
    public function test_money_rounds_2dp(): void
    {
        $this->assertSame(85.0, money(85));
        $this->assertSame(12.5, money(12.5));
        $this->assertSame(10.1, money(10.104));
    }

    public function test_sha256_matches_known(): void
    {
        $this->assertSame(hash('sha256', 'salt:1234'), sha256('salt:1234'));
    }

    public function test_normalize_strips_angle_and_trims_and_limits(): void
    {
        $this->assertSame('abc', normalizeText('  <a>bc  ', 3));
    }

    public function test_boolish(): void
    {
        $this->assertTrue(boolish('TRUE'));
        $this->assertTrue(boolish('1'));
        $this->assertFalse(boolish('no'));
    }

    public function test_uuid_prefixed_has_prefix_and_length(): void
    {
        $id = uuidPrefixed('ord_');
        $this->assertStringStartsWith('ord_', $id);
        $this->assertSame(24, strlen($id)); // prefix 4 + 20 hex
    }
}
