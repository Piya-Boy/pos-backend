<?php

namespace Tests\Unit;

use App\Pos\Sheets\FakeSheetsClient;
use App\Pos\Sheets\SheetRepository;
use PHPUnit\Framework\TestCase;

class SheetRepositoryTest extends TestCase
{
    private function repoWithHeaders(): SheetRepository
    {
        $c = new FakeSheetsClient;
        $c->updateValues('Tables!A1', [['TableID', 'Name', 'Status']]);

        return new SheetRepository($c);
    }

    public function test_append_all_find(): void
    {
        $repo = $this->repoWithHeaders();
        $repo->append('Tables', [['TableID' => 'T01', 'Name' => 'โต๊ะ 01', 'Status' => 'AVAILABLE']]);
        $rows = $repo->all('Tables');
        $this->assertCount(1, $rows);
        $this->assertSame('T01', $rows[0]['TableID']);
        $this->assertSame(2, $rows[0]['_row']);
        $this->assertSame('T01', $repo->find('Tables', 'TableID', 'T01')['TableID']);
    }

    public function test_update_patches_only_present_columns(): void
    {
        $repo = $this->repoWithHeaders();
        $repo->append('Tables', [['TableID' => 'T01', 'Name' => 'โต๊ะ 01', 'Status' => 'AVAILABLE']]);
        $updated = $repo->update('Tables', 'TableID', 'T01', ['Status' => 'OCCUPIED']);
        $this->assertSame('OCCUPIED', $updated['Status']);
        $this->assertSame('โต๊ะ 01', $updated['Name']);
    }

    public function test_upsert_inserts_then_updates(): void
    {
        $repo = $this->repoWithHeaders();
        $repo->upsert('Tables', 'TableID', ['TableID' => 'T02', 'Name' => 'โต๊ะ 02', 'Status' => 'AVAILABLE']);
        $repo->upsert('Tables', 'TableID', ['TableID' => 'T02', 'Name' => 'โต๊ะ 02', 'Status' => 'DISABLED']);
        $this->assertCount(1, $repo->all('Tables'));
        $this->assertSame('DISABLED', $repo->find('Tables', 'TableID', 'T02')['Status']);
    }
}
