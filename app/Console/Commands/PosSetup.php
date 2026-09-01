<?php

namespace App\Console\Commands;

use App\Pos\Sheets\SeedData;
use App\Pos\Sheets\SheetsClient;
use Illuminate\Console\Command;

// / Ports cp-pos setupSystem (Database.js:23). Ensures the 14 sheets exist with
// / headers and seeds default data into any sheet that is still empty. Idempotent.
class PosSetup extends Command
{
    protected $signature = 'pos:setup';

    protected $description = 'Create + seed the Phius Order spreadsheet (14 sheets)';

    public function handle(SheetsClient $client): int
    {
        $defs = SeedData::sheets();
        foreach ($defs as $name => $def) {
            // Ensure header row exists.
            $existing = $client->getValues($name.'!A1:ZZ');
            if (empty($existing)) {
                $client->updateValues($name.'!A1', [$def['headers']]);
                $this->line("created sheet: $name");
            }
            // Seed rows only when the sheet has no data rows yet.
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
