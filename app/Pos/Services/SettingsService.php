<?php

namespace App\Pos\Services;

use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\AppError;
use Illuminate\Support\Facades\Cache;

use function App\Pos\Support\nowIso;
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

    /** Ports adminSaveSettings (Admin.js:287) — brand keys with validation. */
    public function save(array $source): array
    {
        $keys = [
            'AppName', 'RestaurantName', 'RestaurantTagline', 'BrandLogoText', 'BrandLogoURL',
            'PrimaryColor', 'SuccessColor', 'BackgroundColor', 'SurfaceColor', 'TextColor',
            'HeroKicker', 'HeroTitle', 'HeroBadgeText', 'HeroBadgeImageURL', 'CurrencySymbol',
            'ServiceChargePercent', 'VatPercent', 'OrderPollingSeconds',
        ];
        $s = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $source)) {
                $s[$k] = $source[$k];
            }
        }
        $s['AppName'] = \App\Pos\Support\normalizeText($s['AppName'] ?? '', 80);
        $s['RestaurantName'] = \App\Pos\Support\normalizeText($s['RestaurantName'] ?? '', 120);
        $s['RestaurantTagline'] = \App\Pos\Support\normalizeText($s['RestaurantTagline'] ?? '', 160);
        $s['BrandLogoText'] = \App\Pos\Support\normalizeText($s['BrandLogoText'] ?? '', 8);
        $s['HeroKicker'] = \App\Pos\Support\normalizeText($s['HeroKicker'] ?? '', 120);
        $s['HeroTitle'] = \App\Pos\Support\normalizeText($s['HeroTitle'] ?? '', 180);
        $s['HeroBadgeText'] = \App\Pos\Support\normalizeText($s['HeroBadgeText'] ?? '', 20);
        if ($s['AppName'] === '') {
            throw new AppError('APP_NAME_REQUIRED', 'กรุณาระบุชื่อระบบ');
        }
        if ($s['RestaurantName'] === '') {
            throw new AppError('RESTAURANT_NAME_REQUIRED', 'กรุณาระบุชื่อร้าน');
        }
        if ($s['HeroTitle'] === '') {
            throw new AppError('HERO_TITLE_REQUIRED', 'กรุณาระบุข้อความหลักหน้าเมนู');
        }
        $s['BrandLogoURL'] = ($s['BrandLogoURL'] ?? '') !== '' ? \App\Pos\Support\sanitizeHttpsUrl($s['BrandLogoURL']) : '';
        $s['HeroBadgeImageURL'] = ($s['HeroBadgeImageURL'] ?? '') !== '' ? \App\Pos\Support\sanitizeHttpsUrl($s['HeroBadgeImageURL']) : '';
        foreach (['PrimaryColor' => '#B7442B', 'SuccessColor' => '#2F6B4F', 'BackgroundColor' => '#FBF7F0', 'SurfaceColor' => '#FFFFFF', 'TextColor' => '#211E1B'] as $k => $fallback) {
            $s[$k] = $this->hex($s[$k] ?? $fallback);
        }
        $s['CurrencySymbol'] = \App\Pos\Support\normalizeText($s['CurrencySymbol'] ?? '', 4) ?: '฿';
        $s['ServiceChargePercent'] = $this->percent($s['ServiceChargePercent'] ?? 0, 'Service charge');
        $s['VatPercent'] = $this->percent($s['VatPercent'] ?? 0, 'VAT');
        $poll = (int) floor(numberOr($s['OrderPollingSeconds'] ?? 8, 8));
        if ($poll < 5 || $poll > 60) {
            throw new AppError('INVALID_POLLING', 'ความถี่อัปเดตต้องอยู่ระหว่าง 5–60 วินาที');
        }
        $s['OrderPollingSeconds'] = (string) $poll;

        $now = nowIso();
        foreach ($s as $k => $v) {
            $this->repo->upsert('Settings', 'Key', ['Key' => $k, 'Value' => $v, 'UpdatedAt' => $now]);
        }
        $this->forget();

        return ['settings' => $this->map()];
    }

    private function hex(string $v): string
    {
        $color = strtoupper(\App\Pos\Support\normalizeText($v, 7));
        if (! preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new AppError('INVALID_COLOR', 'รหัสสีต้องอยู่ในรูปแบบ #RRGGBB');
        }

        return $color;
    }

    private function percent(mixed $v, string $label): string
    {
        $amount = numberOr($v, 0);
        if ($amount < 0 || $amount > 100) {
            throw new AppError('INVALID_PERCENT', $label.' ต้องอยู่ระหว่าง 0–100');
        }

        return (string) $amount;
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
