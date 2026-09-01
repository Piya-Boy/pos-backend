<?php

namespace App\Pos\Support;

use App\Pos\Sheets\SheetRepository;

// / Ports cp-pos Transactions flow (Services.js:396-415).
// / begin() short-circuits with the cached result when a key already COMPLETED.
class IdempotencyManager
{
    public function __construct(private SheetRepository $repo) {}

    /** Returns decoded ResultJSON if COMPLETED (cache hit), else marks PROCESSING and returns null. */
    public function begin(string $type, string $key): ?array
    {
        $existing = $this->repo->find('Transactions', 'IdempotencyKey', $key);
        if ($existing && (string) $existing['Status'] === 'COMPLETED') {
            $decoded = json_decode((string) ($existing['ResultJSON'] ?? ''), true);

            return is_array($decoded) ? $decoded : null;
        }
        if ($existing) {
            $this->repo->update('Transactions', 'IdempotencyKey', $key, [
                'Type' => $type, 'Status' => 'PROCESSING', 'UpdatedAt' => nowIso(), 'ResultJSON' => '',
            ]);

            return null;
        }
        $this->repo->append('Transactions', [[
            'TransactionID' => uuidPrefixed('txn_'),
            'Type' => $type,
            'IdempotencyKey' => $key,
            'EntityID' => '',
            'Status' => 'PROCESSING',
            'CreatedAt' => nowIso(),
            'UpdatedAt' => nowIso(),
            'ResultJSON' => '',
        ]]);

        return null;
    }

    public function complete(string $key, string $entityId, array $result): void
    {
        $this->repo->update('Transactions', 'IdempotencyKey', $key, [
            'EntityID' => $entityId,
            'Status' => 'COMPLETED',
            'UpdatedAt' => nowIso(),
            'ResultJSON' => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function fail(string $key): void
    {
        try {
            $this->repo->update('Transactions', 'IdempotencyKey', $key, [
                'Status' => 'FAILED', 'UpdatedAt' => nowIso(),
            ]);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
