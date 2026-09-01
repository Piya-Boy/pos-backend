<?php

namespace App\Pos\Sheets;

use App\Pos\Support\AppError;
use Illuminate\Support\Facades\Http;

// / Real SheetsClient over Google Sheets REST API v4. (back.md §3.2)
class GoogleSheetsClient implements SheetsClient
{
    public function __construct(private GoogleTokenProvider $tokens) {}

    private function base(): string
    {
        $id = (string) config('pos.spreadsheet_id');
        if ($id === '') {
            throw new AppError('SPREADSHEET_MISSING', 'ยังไม่ได้ตั้งค่า Spreadsheet');
        }

        return "https://sheets.googleapis.com/v4/spreadsheets/{$id}";
    }

    private function req()
    {
        return Http::withToken($this->tokens->accessToken())
            ->acceptJson()
            ->retry(3, 300, fn ($e, $req) => true);
    }

    public function getValues(string $range): array
    {
        $res = $this->req()->get($this->base()."/values/{$range}");
        if (! $res->ok()) {
            throw new AppError('SHEETS_ERROR', 'อ่านข้อมูลไม่สำเร็จ');
        }

        return $res->json('values', []);
    }

    public function appendValues(string $range, array $rows): void
    {
        $res = $this->req()->post(
            $this->base()."/values/{$range}:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS",
            ['values' => $rows],
        );
        if (! $res->ok()) {
            throw new AppError('SHEETS_ERROR', 'บันทึกข้อมูลไม่สำเร็จ');
        }
    }

    public function updateValues(string $range, array $rows): void
    {
        $res = $this->req()->put(
            $this->base()."/values/{$range}?valueInputOption=RAW",
            ['values' => $rows],
        );
        if (! $res->ok()) {
            throw new AppError('SHEETS_ERROR', 'อัปเดตข้อมูลไม่สำเร็จ');
        }
    }

    public function batchGet(array $ranges): array
    {
        $query = implode('&', array_map(fn ($r) => 'ranges='.rawurlencode($r), $ranges));
        $res = $this->req()->get($this->base().'/values:batchGet?'.$query);
        if (! $res->ok()) {
            throw new AppError('SHEETS_ERROR', 'อ่านข้อมูลไม่สำเร็จ');
        }
        $out = [];
        foreach ($res->json('valueRanges', []) as $vr) {
            $name = strtok((string) ($vr['range'] ?? ''), '!');
            $out[$name] = $vr['values'] ?? [];
        }

        return $out;
    }
}
