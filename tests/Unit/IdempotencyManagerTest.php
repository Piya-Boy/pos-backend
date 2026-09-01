<?php

namespace Tests\Unit;

use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\IdempotencyManager;
use PHPUnit\Framework\TestCase;

class IdempotencyManagerTest extends TestCase
{
    private function repo(): SheetRepository
    {
        $c = new FakeSheetsClient;
        $c->updateValues('Transactions!A1', [[
            'TransactionID', 'Type', 'IdempotencyKey', 'EntityID', 'Status', 'CreatedAt', 'UpdatedAt', 'ResultJSON',
        ]]);

        return new SheetRepository($c);
    }

    public function test_begin_returns_null_first_then_cached_result(): void
    {
        $mgr = new IdempotencyManager($this->repo());
        $this->assertNull($mgr->begin('ORDER', 'k1'));
        $mgr->complete('k1', 'ses_1', ['SessionID' => 'ses_1']);
        $this->assertSame(['SessionID' => 'ses_1'], $mgr->begin('ORDER', 'k1'));
    }

    public function test_fail_marks_failed(): void
    {
        $repo = $this->repo();
        $mgr = new IdempotencyManager($repo);
        $mgr->begin('ORDER', 'k2');
        $mgr->fail('k2');
        $this->assertSame('FAILED', $repo->find('Transactions', 'IdempotencyKey', 'k2')['Status']);
    }
}
