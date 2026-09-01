<?php

namespace App\Pos\Sheets;

// / Google Sheets access boundary. Real impl = GoogleSheetsClient (HTTP v4),
// / test impl = FakeSheetsClient (in-memory). Only SheetRepository calls this.
interface SheetsClient
{
    /** Returns a 2D array of rows (each a list of cell strings); row 0 = headers. */
    public function getValues(string $range): array;

    /** Appends rows after the last non-empty row of the range's sheet. */
    public function appendValues(string $range, array $rows): void;

    /** Overwrites rows starting at the A1 top-left of $range. */
    public function updateValues(string $range, array $rows): void;

    /** Fetches several sheets at once: ['SheetName' => 2D array, ...]. */
    public function batchGet(array $ranges): array;
}
