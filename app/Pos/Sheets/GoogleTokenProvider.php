<?php

namespace App\Pos\Sheets;

use App\Pos\Support\AppError;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// / Service-Account OAuth2 for Sheets. Signs a JWT (RS256) and exchanges it for
// / an access token, cached in Redis until near expiry. (back.md §3.1)
class GoogleTokenProvider
{
    // spreadsheets (read/write) + drive.file (create + share spreadsheets the app makes).
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.file';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function accessToken(): string
    {
        $cached = Cache::get('google:sa:token');
        if ($cached) {
            return $cached;
        }
        $sa = $this->serviceAccount();
        $now = time();
        $jwt = JWT::encode([
            'iss' => $sa['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $sa['private_key'], 'RS256');

        $res = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        if (! $res->ok() || ! $res->json('access_token')) {
            throw new AppError('GOOGLE_AUTH_FAILED', 'ไม่สามารถเชื่อมต่อ Google ได้');
        }
        $token = (string) $res->json('access_token');
        $ttl = max(60, (int) $res->json('expires_in', 3600) - 60);
        Cache::put('google:sa:token', $token, $ttl);

        return $token;
    }

    private function serviceAccount(): array
    {
        $json = (string) config('pos.sa_key_json');
        if ($json === '') {
            $path = (string) config('pos.sa_key_path');
            if (! is_file($path)) {
                throw new AppError('SA_KEY_MISSING', 'ไม่พบไฟล์ Service Account');
            }
            $json = (string) file_get_contents($path);
        }
        $data = json_decode($json, true);
        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            throw new AppError('SA_KEY_INVALID', 'Service Account ไม่ถูกต้อง');
        }

        return $data;
    }
}
