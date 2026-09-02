<?php

namespace App\Pos\Services;

use App\Events\OpsEvent;
use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\AppError;

use function App\Pos\Support\nowIso;

// / Ports cp-pos ops (Admin.js:86-189): dashboard, order-item status, call status.
class OpsService
{
    public function __construct(private SheetRepository $repo) {}

    /** Ports getOpsDashboard (Admin.js:86). $view = KITCHEN|STAFF|CASHIER|ALL. */
    public function dashboard(array $session, string $view): array
    {
        $view = strtoupper($view);
        // Read-only dashboard: short micro-cache so concurrent kitchen/cashier
        // pollers collapse onto one Google read. Writes invalidate these sheets.
        $tables = collect($this->repo->allCached('Tables'))->keyBy(fn ($t) => (string) $t['TableID']);
        $sessions = collect($this->repo->allCached('OrderSessions'));
        $items = collect($this->repo->allCached('OrderItems'));
        $calls = collect($this->repo->allCached('CallLogs'));
        $sessionMap = $sessions->keyBy(fn ($s) => (string) $s['SessionID']);

        $activeItems = $items->filter(function ($item) use ($sessionMap) {
            $s = $sessionMap[(string) $item['SessionID']] ?? null;

            return $s && in_array((string) $s['Status'], ['OPEN', 'PAYMENT_PENDING'], true) && (string) $item['Status'] !== 'CANCELLED';
        })->map(function ($item) use ($sessionMap, $tables) {
            $clean = $this->publicRow($item);
            $s = $sessionMap[(string) $item['SessionID']];
            $clean['TableID'] = $s['TableID'];
            $clean['table'] = $this->publicRow($tables[(string) $s['TableID']] ?? []);
            $clean['options'] = json_decode((string) ($item['OptionsJSON'] ?? '[]'), true) ?: [];
            $clean['addOns'] = json_decode((string) ($item['AddOnsJSON'] ?? '[]'), true) ?: [];
            unset($clean['OptionsJSON'], $clean['AddOnsJSON']);

            return $clean;
        })->values();

        $itemsBySession = $activeItems->groupBy(fn ($i) => (string) $i['SessionID']);

        $activeSessions = $sessions->filter(fn ($s) => in_array((string) $s['Status'], ['OPEN', 'PAYMENT_PENDING'], true))
            ->map(function ($s) use ($tables, $itemsBySession) {
                $clean = $this->publicRow($s);
                $clean['table'] = $this->publicRow($tables[(string) $s['TableID']] ?? []);
                $clean['items'] = ($itemsBySession[(string) $s['SessionID']] ?? collect())->all();

                return $clean;
            })->values();

        $activeCalls = $calls->filter(fn ($c) => in_array((string) $c['Status'], ['OPEN', 'ASSIGNED'], true))
            ->map(function ($c) use ($tables) {
                $clean = $this->publicRow($c);
                $clean['table'] = $this->publicRow($tables[(string) $c['TableID']] ?? []);

                return $clean;
            })->values();

        return [
            'user' => $session,
            'view' => $view,
            'items' => $view === 'CASHIER' ? [] : $activeItems->all(),
            'sessions' => in_array($view, ['CASHIER', 'ALL'], true) ? $activeSessions->all() : [],
            'calls' => in_array($view, ['STAFF', 'ALL'], true) ? $activeCalls->all() : [],
            'summary' => [
                'openTables' => $activeSessions->count(),
                'newOrders' => $activeItems->where('Status', 'NEW')->count(),
                'preparing' => $activeItems->where('Status', 'PREPARING')->count(),
                'ready' => $activeItems->where('Status', 'READY')->count(),
                'waitingCalls' => $calls->where('Status', 'OPEN')->count(),
            ],
        ];
    }

    /** Ports updateOrderItemStatus (Admin.js:153). */
    public function updateOrderItem(array $session, array $input): array
    {
        $target = strtoupper((string) ($input['status'] ?? ''));
        $item = $this->repo->find('OrderItems', 'OrderItemID', (string) ($input['orderItemId'] ?? ''));
        if (! $item) {
            throw new AppError('ITEM_NOT_FOUND', 'ไม่พบรายการอาหาร');
        }
        $roleTargets = [
            'KITCHEN' => ['PREPARING', 'READY'],
            'STAFF' => ['SERVED'],
            'ADMIN' => ['NEW', 'PREPARING', 'READY', 'SERVED', 'CANCELLED'],
        ];
        if (! in_array($target, $roleTargets[(string) $session['role']] ?? [], true)) {
            throw new AppError('INVALID_STATUS', 'ไม่สามารถเปลี่ยนเป็นสถานะนี้ได้');
        }
        $updated = $this->repo->update('OrderItems', 'OrderItemID', (string) $item['OrderItemID'], [
            'Status' => $target,
            'KitchenNote' => (string) ($input['kitchenNote'] ?? $item['KitchenNote'] ?? ''),
            'UpdatedAt' => nowIso(),
        ]);
        OpsEvent::dispatch('ITEM_STATUS', $this->tableTokenForSession((string) $item['SessionID']));

        return $this->publicRow($updated);
    }

    /** Resolves the table token for a session so events can reach that customer. */
    private function tableTokenForSession(string $sessionId): string
    {
        $session = collect($this->repo->all('OrderSessions'))
            ->first(fn ($s) => (string) $s['SessionID'] === $sessionId);
        if (! $session) {
            return '';
        }
        $table = collect($this->repo->all('Tables'))
            ->first(fn ($t) => (string) $t['TableID'] === (string) $session['TableID']);

        return $table ? (string) $table['Token'] : '';
    }

    /** Ports updateCallStatus (Admin.js:174). */
    public function updateCall(array $session, array $input): array
    {
        $call = $this->repo->find('CallLogs', 'LogID', (string) ($input['logId'] ?? ''));
        if (! $call) {
            throw new AppError('CALL_NOT_FOUND', 'ไม่พบงานเรียกนี้');
        }
        $status = strtoupper((string) ($input['status'] ?? ''));
        if (! in_array($status, ['ASSIGNED', 'DONE'], true)) {
            throw new AppError('INVALID_STATUS', 'สถานะงานไม่ถูกต้อง');
        }
        $patch = ['Status' => $status, 'AssignedStaffID' => (string) $session['staffId']];
        if ($status === 'ASSIGNED') {
            $patch['AcceptedAt'] = nowIso();
        }
        if ($status === 'DONE') {
            $patch['CompletedAt'] = nowIso();
        }
        $updated = $this->repo->update('CallLogs', 'LogID', (string) $call['LogID'], $patch);
        OpsEvent::dispatch('CALL_STATUS'); // staff-side only

        return $this->publicRow($updated);
    }

    private function publicRow(array $row): array
    {
        unset($row['_row'], $row['PINHash']);

        return $row;
    }
}
