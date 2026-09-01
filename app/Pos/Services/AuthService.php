<?php

namespace App\Pos\Services;

use App\Pos\Sheets\SeedData;
use App\Pos\Sheets\SheetRepository;
use App\Pos\Support\AppError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

use function App\Pos\Support\nowIso;
use function App\Pos\Support\sha256;

// / Ports cp-pos staff auth (Admin.js:18-84). Opaque token in cache (Redis in
// / prod, array in tests). Not Sanctum. PIN hash = sha256(salt:pin).
class AuthService
{
    public function __construct(private SheetRepository $repo) {}

    private function salt(): string
    {
        $configured = (string) config('pos.auth_salt');
        if ($configured !== '') {
            return $configured;
        }
        // Fallback stable salt from cache (dev only). In prod set POS_AUTH_SALT.
        $salt = Cache::get('pos:auth-salt');
        if (! $salt) {
            // Match the fixed seed salt so seeded PINs verify in tests/dev.
            $salt = SeedData::SALT;
            Cache::forever('pos:auth-salt', $salt);
        }

        return $salt;
    }

    private function ttl(): int
    {
        return (int) config('pos.auth_ttl', 21600);
    }

    public function login(string $pin, string $expectedRole = ''): array
    {
        $pin = trim($pin);
        if (! preg_match('/^[A-Za-z0-9]{4,12}$/', $pin)) {
            throw new AppError('INVALID_PIN', 'PIN ต้องเป็นตัวอักษรภาษาอังกฤษหรือตัวเลข 4–12 ตัว');
        }
        $hash = sha256($this->salt().':'.$pin);
        $expectedRole = strtoupper(trim($expectedRole));
        $matches = collect($this->repo->all('Staff'))
            ->filter(fn ($r) => (string) $r['PINHash'] === $hash && (string) $r['Status'] === 'ACTIVE');
        $staff = $matches->first(fn ($r) => $expectedRole === '' || (string) $r['Role'] === $expectedRole)
            ?? $matches->first(fn ($r) => $expectedRole !== '' && (string) $r['Role'] === 'ADMIN');
        if (! $staff) {
            throw new AppError('LOGIN_FAILED', 'PIN หรือบทบาทไม่ถูกต้อง');
        }
        $token = Str::random(64);
        $session = [
            'staffId' => (string) $staff['StaffID'],
            'name' => (string) $staff['Name'],
            'role' => (string) $staff['Role'],
            'issuedAt' => nowIso(),
            'mustChangePin' => in_array(strtolower((string) $staff['MustChangePin']), ['true', '1'], true),
        ];
        Cache::put('auth:'.$token, $session, $this->ttl());
        $this->repo->update('Staff', 'StaffID', (string) $staff['StaffID'], ['LastLogin' => nowIso(), 'UpdatedAt' => nowIso()]);
        $this->audit((string) $staff['StaffID'], 'LOGIN', 'Staff', (string) $staff['StaffID'], ['role' => $staff['Role']]);

        return ['token' => $token, 'user' => $session];
    }

    public function logout(string $token): array
    {
        if ($token !== '') {
            Cache::forget('auth:'.$token);
        }

        return ['loggedOut' => true];
    }

    public function changePin(array $session, string $newPin): array
    {
        $newPin = trim($newPin);
        if (! preg_match('/^[A-Za-z0-9]{6,12}$/', $newPin)) {
            throw new AppError('INVALID_PIN', 'PIN ใหม่ต้องเป็นตัวอักษรภาษาอังกฤษหรือตัวเลข 6–12 ตัว');
        }
        if ($newPin === (string) config('pos.initial_pin', 'zaq1234')) {
            throw new AppError('PIN_REUSE', 'กรุณาตั้ง PIN ใหม่ที่ไม่ใช่รหัสเริ่มต้น');
        }
        $this->repo->update('Staff', 'StaffID', (string) $session['staffId'], [
            'PINHash' => sha256($this->salt().':'.$newPin), 'MustChangePin' => 'FALSE', 'UpdatedAt' => nowIso(),
        ]);
        $this->audit((string) $session['staffId'], 'CHANGE_PIN', 'Staff', (string) $session['staffId'], []);

        return ['changed' => true];
    }

    /** Ports requireAuth_ — resolve token to session, enforce role (ADMIN overrides). */
    public function resolve(string $token, array $roles = []): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new AppError('AUTH_REQUIRED', 'กรุณาเข้าสู่ระบบ');
        }
        $session = Cache::get('auth:'.$token);
        if (! $session) {
            throw new AppError('AUTH_EXPIRED', 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่');
        }
        // allCached: resolve() runs on every authed request (every poll). A few
        // seconds of staleness on Staff is fine — archiving an account writes to
        // Staff, which invalidates this cache. Login/PIN changes read fresh below.
        $staff = collect($this->repo->allCached('Staff'))
            ->first(fn ($r) => (string) $r['StaffID'] === (string) $session['staffId']);
        if (! $staff || (string) $staff['Status'] !== 'ACTIVE') {
            throw new AppError('PERMISSION_DENIED', 'บัญชีนี้ไม่พร้อมใช้งาน');
        }
        $session['role'] = (string) $staff['Role'];
        $session['name'] = (string) $staff['Name'];
        $session['mustChangePin'] = in_array(strtolower((string) $staff['MustChangePin']), ['true', '1'], true);
        if (! empty($roles) && ! in_array($session['role'], $roles, true) && $session['role'] !== 'ADMIN') {
            throw new AppError('PERMISSION_DENIED', 'คุณไม่มีสิทธิ์ทำรายการนี้');
        }
        Cache::put('auth:'.$token, $session, $this->ttl());

        return $session;
    }

    private function audit(string $staffId, string $action, string $entityType, string $entityId, array $detail): void
    {
        $this->repo->append('AuditLog', [[
            'Timestamp' => nowIso(), 'StaffID' => $staffId, 'Action' => $action,
            'EntityType' => $entityType, 'EntityID' => $entityId, 'DetailJSON' => json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]]);
    }
}
