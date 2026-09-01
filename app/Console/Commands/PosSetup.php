<?php

namespace App\Console\Commands;

use App\Pos\Sheets\GoogleSheetsClient;
use App\Pos\Sheets\SeedData;
use App\Pos\Sheets\SheetsClient;
use Illuminate\Console\Command;

// / Ports cp-pos setupSystem (Database.js:23). With --create, the app makes its
// / OWN spreadsheet (like SpreadsheetApp.create) — no manual Sheet setup needed.
// / Otherwise it ensures + seeds the 14 sheets in GOOGLE_SPREADSHEET_ID.
class PosSetup extends Command
{
    protected $signature = 'pos:setup {--create : create a brand-new spreadsheet} {--share= : email to share the created sheet with}';

    protected $description = 'Create + seed the Phius Order spreadsheet (14 sheets)';

    public function handle(SheetsClient $client): int
    {
        $defs = SeedData::sheets();
        $names = array_keys($defs);

        if ($this->option('create')) {
            if (! $client instanceof GoogleSheetsClient) {
                $this->error('--create needs the real Google client (not fake).');

                return self::FAILURE;
            }
            $id = $client->createSpreadsheet('Phius Order Database', $names);
            $this->info("created spreadsheet: $id");
            $this->line("URL: https://docs.google.com/spreadsheets/d/$id/edit");
            if ($share = $this->option('share')) {
                $client->shareWith($id, $share);
                $this->line("shared with: $share");
            }
            $this->warn('เพิ่มลง .env:  GOOGLE_SPREADSHEET_ID='.$id);
            $this->warn('แล้วรัน pos:setup อีกครั้ง (ไม่ใส่ --create) เพื่อ seed ข้อมูล');

            // config already resolved; seeding now would target the old id, so stop here.
            return self::SUCCESS;
        }

        foreach ($defs as $name => $def) {
            $existing = $client->getValues($name.'!A1:ZZ');
            if (empty($existing)) {
                $client->updateValues($name.'!A1', [$def['headers']]);
                $this->line("created sheet: $name");
            }
            $current = $client->getValues($name.'!A1:ZZ');
            $dataRows = max(0, count($current) - 1);
            if ($dataRows === 0 && ! empty($def['rows'])) {
                $client->appendValues($name.'!A1', $def['rows']);
                $this->line("seeded $name: ".count($def['rows']).' rows');
            }
        }

        $this->info('ระบบถูกตั้งค่าแล้ว — PIN เริ่มต้นคือ '.SeedData::INITIAL_PIN.' และต้องเปลี่ยนหลังเข้าสู่ระบบครั้งแรก');

        return self::SUCCESS;
    }
}
