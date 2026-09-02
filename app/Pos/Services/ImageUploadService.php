<?php

namespace App\Pos\Services;

use App\Pos\Sheets\GoogleTokenProvider;
use App\Pos\Support\AppError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

use function App\Pos\Support\sanitizeHttpsUrl;
use function App\Pos\Support\uuidPrefixed;

// / E3: uploads an admin image (menu/promotion/brand) to Google Drive and returns
// / a public, https image URL. Ports the intent of cp-pos compressImage/upload
// / (App.html:1242) — validation lives server-side; never trust the client mime.
class ImageUploadService
{
    private const MAX_BYTES = 7 * 1024 * 1024; // 7MB, matches cp-pos limit

    /** magic-byte signatures → canonical mime (do not trust the client mime). */
    private const SIGNATURES = [
        "\xFF\xD8\xFF" => 'image/jpeg',
        "\x89PNG\r\n\x1a\n" => 'image/png',
        'RIFF' => 'image/webp', // WEBP is RIFF-based; refined below
        'GIF87a' => 'image/gif',
        'GIF89a' => 'image/gif',
    ];

    public function __construct(private GoogleTokenProvider $tokens) {}

    /** Validates + uploads; returns the public https URL to store as ImageURL. */
    public function upload(?UploadedFile $file): string
    {
        if (! $file || ! $file->isValid()) {
            throw new AppError('IMAGE_REQUIRED', 'กรุณาเลือกไฟล์รูปภาพ');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new AppError('IMAGE_TOO_LARGE', 'ไฟล์รูปต้องไม่เกิน 7MB');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $this->detectMime($bytes);
        if ($mime === null) {
            throw new AppError('IMAGE_INVALID', 'รองรับเฉพาะไฟล์ JPEG, PNG, WEBP หรือ GIF');
        }

        $token = $this->tokens->accessToken();
        $id = $this->uploadToDrive($token, $bytes, $mime);
        $this->makePublic($token, $id);

        // Direct-view link that renders in <img> (Drive's uc?export=view form).
        return sanitizeHttpsUrl("https://drive.google.com/uc?export=view&id={$id}");
    }

    /** Detects the real mime from magic bytes; returns null if unsupported. */
    private function detectMime(string $bytes): ?string
    {
        foreach (self::SIGNATURES as $sig => $mime) {
            if (str_starts_with($bytes, $sig)) {
                if ($mime === 'image/webp') {
                    // RIFF....WEBP — confirm the WEBP fourcc at offset 8.
                    return substr($bytes, 8, 4) === 'WEBP' ? 'image/webp' : null;
                }

                return $mime;
            }
        }

        return null;
    }

    /** Multipart upload to Drive; returns the new file id. */
    private function uploadToDrive(string $token, string $bytes, string $mime): string
    {
        $meta = ['name' => uuidPrefixed('img_').$this->ext($mime)];
        $boundary = 'phius'.bin2hex(substr(hash('sha256', $bytes, true), 0, 8));
        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .json_encode($meta)."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$mime}\r\n\r\n"
            .$bytes."\r\n"
            ."--{$boundary}--";

        $res = Http::withToken($token)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');

        if (! $res->ok() || ! $res->json('id')) {
            throw new AppError('IMAGE_UPLOAD_FAILED', 'อัปโหลดรูปไม่สำเร็จ');
        }

        return (string) $res->json('id');
    }

    /** Grants anyone-with-link read so the image renders on public menu cards. */
    private function makePublic(string $token, string $fileId): void
    {
        $res = Http::withToken($token)->acceptJson()->post(
            "https://www.googleapis.com/drive/v3/files/{$fileId}/permissions",
            ['role' => 'reader', 'type' => 'anyone'],
        );
        if (! $res->ok()) {
            throw new AppError('IMAGE_SHARE_FAILED', 'ตั้งค่าการเข้าถึงรูปไม่สำเร็จ');
        }
    }

    private function ext(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
            default => '',
        };
    }
}
