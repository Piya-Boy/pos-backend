<?php

namespace Tests\Unit;

use App\Pos\Sheets\FakeSheetsClient;
use PHPUnit\Framework\TestCase;

class FakeSheetsClientTest extends TestCase
{
    public function test_append_then_get(): void
    {
        $c = new FakeSheetsClient;
        $c->updateValues('T!A1:B1', [['H1', 'H2']]);
        $c->appendValues('T!A1', [['a', 'b']]);
        $values = $c->getValues('T!A1:Z');
        $this->assertSame(['H1', 'H2'], $values[0]);
        $this->assertSame(['a', 'b'], $values[1]);
    }

    public function test_update_overwrites_row(): void
    {
        $c = new FakeSheetsClient;
        $c->updateValues('T!A1', [['H1', 'H2']]);
        $c->appendValues('T!A1', [['a', 'b'], ['c', 'd']]);
        $c->updateValues('T!A2', [['x', 'y']]); // row 2 (first data row)
        $values = $c->getValues('T!A1:Z');
        $this->assertSame(['x', 'y'], $values[1]);
        $this->assertSame(['c', 'd'], $values[2]);
    }
}
