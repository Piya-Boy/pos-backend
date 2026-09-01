<?php

namespace App\Pos\Services;

use App\Pos\Sheets\SheetRepository;
use Illuminate\Support\Facades\Cache;

use function App\Pos\Support\numberOr;

// / Ports cp-pos settings/bootstrap (Services.js:1-46, Database.js seedSettings_).
class SettingsService
{
    public function __construct(private SheetRepository $repo) {}

    /** Settings sheet -> key=>value map, cached 120s. */
    public function map(): array
    {
        return Cache::remember('pos:settings-map', 120, function () {
            $out = [];
            foreach ($this->repo->all('Settings') as $row) {
                $out[(string) $row['Key']] = (string) ($row['Value'] ?? '');
            }

            return $out;
        });
    }

    public function forget(): void
    {
        Cache::forget('pos:settings-map');
    }

    /** Ports getPublicBootstrap (Services.js:1). */
    public function bootstrap(?string $tableToken): array
    {
        $s = $this->map();
        $app = [
            'appName' => $s['AppName'] ?? 'Phius Order',
            'name' => $s['RestaurantName'] ?? 'Phius Order',
            'restaurantName' => $s['RestaurantName'] ?? 'Phius Order',
            'tagline' => $s['RestaurantTagline'] ?? '',
            'logoText' => $s['BrandLogoText'] ?? 'ผ',
            'logoUrl' => $s['BrandLogoURL'] ?? '',
            'primaryColor' => $s['PrimaryColor'] ?? '#B7442B',
            'successColor' => $s['SuccessColor'] ?? '#2F6B4F',
            'backgroundColor' => $s['BackgroundColor'] ?? '#FBF7F0',
            'surfaceColor' => $s['SurfaceColor'] ?? '#FFFFFF',
            'textColor' => $s['TextColor'] ?? '#211E1B',
            'heroKicker' => $s['HeroKicker'] ?? 'อิ่มอร่อยในแบบของคุณ',
            'heroTitle' => $s['HeroTitle'] ?? "เลือกเมนูโปรด\nแล้วส่งตรงถึงครัว",
            'heroBadgeText' => $s['HeroBadgeText'] ?? 'อร่อย',
            'heroBadgeImageUrl' => $s['HeroBadgeImageURL'] ?? '',
            'currency' => $s['Currency'] ?? 'THB',
            'currencySymbol' => $s['CurrencySymbol'] ?? '฿',
            'pollSeconds' => (int) numberOr($s['OrderPollingSeconds'] ?? 8, 8),
            'version' => '1.3.1',
        ];

        return [
            'setupRequired' => false,
            'app' => $app,
        ];
    }
}
