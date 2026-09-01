<?php

namespace App\Pos\Services;

use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\AppError;
use Illuminate\Support\Facades\Cache;

use function App\Pos\Support\numberOr;

// / Ports cp-pos catalog (Services.js:55-145). Joins categories + menu
// / (active options + scoped add-ons) + active promotions; cached 120s.
class CatalogService
{
    public function __construct(private SheetRepository $repo) {}

    public function clearCatalogCache(): void
    {
        Cache::forget('pos:public-catalog');
        Cache::forget('pos:tables');
    }

    /**
     * Tables keyed by Token, cached briefly. Short TTL because a rotated or
     * disabled QR must stop working soon; admin writes to Tables also call
     * clearCatalogCache() for immediate invalidation.
     *
     * @return array<string, array>
     */
    private function tablesByToken(): array
    {
        return Cache::remember('pos:tables', 15, function () {
            $out = [];
            foreach ($this->repo->all('Tables') as $row) {
                $out[(string) $row['Token']] = $row;
            }

            return $out;
        });
    }

    public function publicCatalog(): array
    {
        return Cache::remember('pos:public-catalog', 120, fn () => $this->buildCatalog());
    }

    private function buildCatalog(): array
    {
        $categories = collect($this->repo->all('Categories'))
            ->filter(fn ($c) => (string) $c['Status'] === 'ACTIVE')
            ->sortBy(fn ($c) => numberOr($c['SortOrder'] ?? 0))
            ->values();

        $options = collect($this->repo->all('Options'))
            ->filter(fn ($o) => (string) $o['Status'] === 'ACTIVE');
        $addOns = collect($this->repo->all('AddOns'))
            ->filter(fn ($a) => (string) $a['Status'] === 'ACTIVE');

        $optionsByItem = $options->groupBy(fn ($o) => (string) $o['ItemID']);

        $globalAddOns = [];
        $addOnsByItem = [];
        $addOnsByCategory = [];
        foreach ($addOns as $addOn) {
            $itemKey = (string) ($addOn['LinkedItemID'] ?? '');
            $catKey = (string) ($addOn['LinkedCategoryID'] ?? '');
            if (($itemKey === '' && $catKey === '') || $itemKey === 'ALL' || $catKey === 'ALL') {
                $globalAddOns[] = $addOn;

                continue;
            }
            if ($itemKey !== '') {
                $addOnsByItem[$itemKey][] = $addOn;
            }
            if ($catKey !== '') {
                $addOnsByCategory[$catKey][] = $addOn;
            }
        }

        $menu = collect($this->repo->all('MenuItems'))
            ->filter(fn ($m) => (string) $m['Status'] !== 'ARCHIVED')
            ->sortBy(fn ($m) => numberOr($m['SortOrder'] ?? 0))
            ->map(function ($item) use ($optionsByItem, $globalAddOns, $addOnsByItem, $addOnsByCategory) {
                $clean = $this->publicRow($item);
                $clean['available'] = (string) $item['Status'] === 'ACTIVE';
                $clean['options'] = ($optionsByItem[(string) $item['ItemID']] ?? collect())
                    ->map(fn ($o) => $this->publicRow($o))->values()->all();

                $scoped = array_merge(
                    $globalAddOns,
                    $addOnsByItem[(string) $item['ItemID']] ?? [],
                    $addOnsByCategory[(string) $item['CategoryID']] ?? [],
                );
                $seen = [];
                $clean['addOns'] = [];
                foreach ($scoped as $a) {
                    $id = (string) $a['AddOnID'];
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $clean['addOns'][] = $this->publicRow($a);
                }

                return $clean;
            })->values();

        return [
            'categories' => $categories->map(fn ($c) => $this->publicRow($c))->all(),
            'menu' => $menu->all(),
            'promotions' => array_map(fn ($p) => $this->publicRow($p), $this->activePromotions()),
        ];
    }

    /** Ports getCustomerData_ (Services.js:55). */
    public function customerData(string $tableToken): array
    {
        if ($tableToken === '') {
            throw new AppError('TABLE_REQUIRED', 'ไม่พบข้อมูลโต๊ะ กรุณาสแกน QR ใหม่');
        }
        $table = $this->tablesByToken()[$tableToken] ?? null;
        if (! $table || (string) $table['Status'] === 'DISABLED') {
            throw new AppError('TABLE_NOT_FOUND', 'QR โต๊ะนี้ไม่พร้อมใช้งาน');
        }
        $catalog = $this->publicCatalog();

        return [
            'table' => [
                'TableID' => $table['TableID'],
                'Name' => $table['Name'],
                'Zone' => $table['Zone'],
                'Status' => $table['Status'],
            ],
            'categories' => $catalog['categories'],
            'menu' => $catalog['menu'],
            'promotions' => $catalog['promotions'],
            'session' => null, // session bundle wired by OrderService (Task 6)
        ];
    }

    public function activePromotions(): array
    {
        $today = now('Asia/Bangkok')->format('Y-m-d');

        return collect($this->repo->all('Promotions'))
            ->filter(function ($p) use ($today) {
                if ((string) $p['Status'] !== 'ACTIVE') {
                    return false;
                }
                $start = substr((string) ($p['StartDate'] ?? ''), 0, 10);
                $end = substr((string) ($p['EndDate'] ?? ''), 0, 10);

                return ($start === '' || $start <= $today) && ($end === '' || $end >= $today);
            })
            ->values()
            ->all();
    }

    public function addOnAppliesToItem(array $addOn, array $item): bool
    {
        $li = (string) ($addOn['LinkedItemID'] ?? '');
        $lc = (string) ($addOn['LinkedCategoryID'] ?? '');
        if ($li === '' && $lc === '') {
            return true;
        }
        if ($li === 'ALL' || $lc === 'ALL') {
            return true;
        }

        return $li === (string) $item['ItemID'] || $lc === (string) $item['CategoryID'];
    }

    private function publicRow(array $row): array
    {
        unset($row['_row'], $row['PINHash']);

        return $row;
    }
}
