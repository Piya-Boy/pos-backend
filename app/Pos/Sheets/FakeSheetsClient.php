<?php

namespace App\Pos\Sheets;

// / In-memory SheetsClient for offline tests. Rows keyed by sheet name.
class FakeSheetsClient implements SheetsClient
{
    /** @var array<string, array<int, array<int, string>>> */
    private array $sheets = [];

    private function sheetName(string $range): string
    {
        $pos = strpos($range, '!');

        return $pos === false ? $range : substr($range, 0, $pos);
    }

    /** Parse 1-based start row from an A1 range (default 1). */
    private function startRow(string $range): int
    {
        $pos = strpos($range, '!');
        if ($pos === false) {
            return 1;
        }
        $a1 = substr($range, $pos + 1); // e.g. "A3:Z" or "A1"
        if (preg_match('/[A-Z]+(\d+)/i', $a1, $m)) {
            return (int) $m[1];
        }

        return 1;
    }

    public function getValues(string $range): array
    {
        return $this->sheets[$this->sheetName($range)] ?? [];
    }

    public function appendValues(string $range, array $rows): void
    {
        $name = $this->sheetName($range);
        $this->sheets[$name] ??= [];
        foreach ($rows as $row) {
            $this->sheets[$name][] = array_map(static fn ($v) => (string) ($v ?? ''), $row);
        }
    }

    public function updateValues(string $range, array $rows): void
    {
        $name = $this->sheets[$this->sheetName($range)] ?? [];
        $sheetName = $this->sheetName($range);
        $this->sheets[$sheetName] ??= [];
        $start = $this->startRow($range) - 1; // 0-based
        foreach ($rows as $i => $row) {
            $this->sheets[$sheetName][$start + $i] =
                array_map(static fn ($v) => (string) ($v ?? ''), $row);
        }
        ksort($this->sheets[$sheetName]);
    }

    public function batchGet(array $ranges): array
    {
        $out = [];
        foreach ($ranges as $range) {
            $out[$this->sheetName($range)] = $this->getValues($range);
        }

        return $out;
    }

    /** Seeds the 14 sheets with default data (SeedData). */
    public function seedDefaults(): static
    {
        foreach (SeedData::sheets() as $name => $def) {
            $this->sheets[$name] = [];
            $this->sheets[$name][] = array_map('strval', $def['headers']);
            foreach ($def['rows'] as $row) {
                $this->sheets[$name][] = array_map(static fn ($v) => (string) $v, $row);
            }
        }

        return $this;
    }

    /** First seeded table Token (for tests). */
    public function firstTableToken(): string
    {
        $tables = $this->sheets['Tables'] ?? [];
        if (count($tables) < 2) {
            return '';
        }
        $tokenCol = array_search('Token', $tables[0], true);

        return $tokenCol === false ? '' : (string) ($tables[1][$tokenCol] ?? '');
    }
}
