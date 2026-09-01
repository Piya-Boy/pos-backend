<?php

namespace App\Pos\Support;

use App\Pos\Services\SettingsService;
use App\Pos\Sheets\SheetRepository;

// / Ports recalculateSessionTotals_ (Services.js:357-379). Computes + persists
// / session totals: subtotal -> promo discount -> service charge -> VAT -> total.
class Totals
{
    public static function calculate(
        SheetRepository $repo,
        SettingsService $settings,
        string $sessionId,
        string $promoCode,
    ): array {
        $s = $settings->map();
        $items = collect($repo->all('OrderItems'))
            ->filter(fn ($i) => (string) $i['SessionID'] === $sessionId && (string) $i['Status'] !== 'CANCELLED');
        $subtotal = money($items->sum(fn ($i) => numberOr($i['LineTotal'] ?? 0)));

        $promo = self::findPromotion($repo, $promoCode, $subtotal);
        $discount = 0.0;
        if ($promo) {
            $discount = (string) $promo['DiscountType'] === 'PERCENT'
                ? money($subtotal * numberOr($promo['DiscountValue']) / 100)
                : min($subtotal, money(numberOr($promo['DiscountValue'])));
        }
        $net = max(0, $subtotal - $discount);
        $serviceCharge = money($net * numberOr($s['ServiceChargePercent'] ?? 0) / 100);
        $vat = money(($net + $serviceCharge) * numberOr($s['VatPercent'] ?? 0) / 100);
        $total = money($net + $serviceCharge + $vat);

        $repo->update('OrderSessions', 'SessionID', $sessionId, [
            'Subtotal' => $subtotal,
            'Discount' => $discount,
            'ServiceCharge' => $serviceCharge,
            'Vat' => $vat,
            'Total' => $total,
            'PromoCode' => $promo ? $promo['Code'] : '',
            'UpdatedAt' => nowIso(),
        ]);

        return [
            'subtotal' => $subtotal,
            'discount' => (float) $discount,
            'serviceCharge' => $serviceCharge,
            'vat' => $vat,
            'total' => $total,
            'promo' => $promo ? self::publicRow($promo) : null,
        ];
    }

    public static function findPromotion(SheetRepository $repo, string $code, float $subtotal): ?array
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }
        $today = now('Asia/Bangkok')->format('Y-m-d');
        foreach ($repo->all('Promotions') as $p) {
            if ((string) $p['Status'] !== 'ACTIVE') {
                continue;
            }
            $start = substr((string) ($p['StartDate'] ?? ''), 0, 10);
            $end = substr((string) ($p['EndDate'] ?? ''), 0, 10);
            $inWindow = ($start === '' || $start <= $today) && ($end === '' || $end >= $today);
            if (! $inWindow) {
                continue;
            }
            if (strtoupper((string) $p['Code']) === $normalized && $subtotal >= numberOr($p['MinSpend'])) {
                return $p;
            }
        }

        return null;
    }

    private static function publicRow(array $row): array
    {
        unset($row['_row']);

        return $row;
    }
}
