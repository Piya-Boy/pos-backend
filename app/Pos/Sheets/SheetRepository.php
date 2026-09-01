<?php

namespace App\Pos\Sheets;

use App\Pos\Support\AppError;

// / Row <-> assoc-array mapping over SheetsClient. Ports cp-pos Database.js
// / (readSheetObjects_/findObject_/appendObjects_/updateObject_/upsertObject_).
// / Write path always reads fresh (never cached) so _row indexes are valid.
class SheetRepository
{
    public function __construct(private SheetsClient $client) {}

    private function headers(string $sheet): array
    {
        $values = $this->client->getValues($sheet.'!A1:ZZ');

        return isset($values[0]) ? array_map('strval', $values[0]) : [];
    }

    /** All rows as assoc arrays with `_row` (1-based sheet row). Skips empty rows. */
    public function all(string $sheet): array
    {
        $values = $this->client->getValues($sheet.'!A1:ZZ');
        if (count($values) < 2) {
            return [];
        }
        $headers = array_map('strval', $values[0]);
        $out = [];
        foreach (array_slice($values, 1, null, true) as $index => $row) {
            $hasValue = false;
            foreach ($row as $cell) {
                if ((string) $cell !== '') {
                    $hasValue = true;
                    break;
                }
            }
            if (! $hasValue) {
                continue;
            }
            $obj = ['_row' => $index + 1]; // $index is 0-based into full values; +1 => 1-based row
            foreach ($headers as $col => $header) {
                $obj[$header] = $row[$col] ?? '';
            }
            $out[] = $obj;
        }

        return $out;
    }

    public function find(string $sheet, string $keyField, string $value): ?array
    {
        foreach ($this->all($sheet) as $row) {
            if ((string) ($row[$keyField] ?? '') === (string) $value) {
                return $row;
            }
        }

        return null;
    }

    public function append(string $sheet, array $objects): void
    {
        if (empty($objects)) {
            return;
        }
        $headers = $this->headers($sheet);
        $rows = [];
        foreach ($objects as $obj) {
            $rows[] = array_map(static fn ($h) => (string) ($obj[$h] ?? ''), $headers);
        }
        $this->client->appendValues($sheet.'!A1', $rows);
    }

    public function update(string $sheet, string $keyField, string $keyValue, array $patch): array
    {
        $values = $this->client->getValues($sheet.'!A1:ZZ');
        if (empty($values)) {
            throw new AppError('SYSTEM_NOT_READY', 'ไม่พบโครงสร้างข้อมูล');
        }
        $headers = array_map('strval', $values[0]);
        $keyCol = array_search($keyField, $headers, true);
        if ($keyCol === false) {
            throw new AppError('SCHEMA_ERROR', 'ไม่พบคอลัมน์ '.$keyField);
        }
        $rowIndex = -1; // 0-based into $values
        for ($i = 1; $i < count($values); $i++) {
            if ((string) ($values[$i][$keyCol] ?? '') === (string) $keyValue) {
                $rowIndex = $i;
                break;
            }
        }
        if ($rowIndex === -1) {
            throw new AppError('NOT_FOUND', 'ไม่พบข้อมูลที่ต้องการ');
        }
        $row = $values[$rowIndex];
        foreach ($patch as $field => $val) {
            $col = array_search($field, $headers, true);
            if ($col !== false) {
                $row[$col] = (string) ($val ?? '');
            }
        }
        // pad row to header width
        for ($c = 0; $c < count($headers); $c++) {
            $row[$c] ??= '';
        }
        $this->client->updateValues($sheet.'!A'.($rowIndex + 1), [array_values($row)]);

        return $this->find($sheet, $keyField, $keyValue) ?? [];
    }

    public function upsert(string $sheet, string $keyField, array $object): array
    {
        $existing = $this->find($sheet, $keyField, (string) ($object[$keyField] ?? ''));
        if ($existing) {
            return $this->update($sheet, $keyField, (string) $object[$keyField], $object);
        }
        $this->append($sheet, [$object]);

        return $this->find($sheet, $keyField, (string) ($object[$keyField] ?? '')) ?? [];
    }
}
