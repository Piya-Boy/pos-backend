<?php

namespace Tests\Feature;

use App\Pos\Services\ImageUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImageUploadServiceTest extends TestCase
{
    private function pngBytes(): string
    {
        // minimal PNG magic header + filler
        return "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64);
    }

    private function service(): ImageUploadService
    {
        return $this->app->make(ImageUploadService::class);
    }

    public function test_rejects_non_image_by_magic_bytes(): void
    {
        Http::fake();
        $file = UploadedFile::fake()->createWithContent('evil.png', 'not-an-image');
        $this->expectExceptionMessage('รองรับเฉพาะไฟล์');
        $this->service()->upload($file);
    }

    public function test_rejects_oversized_file(): void
    {
        Http::fake();
        // 8MB > 7MB cap
        $file = UploadedFile::fake()->create('big.png', 8 * 1024);
        $this->expectExceptionMessage('ไม่เกิน 7MB');
        $this->service()->upload($file);
    }

    public function test_uploads_valid_png_and_returns_public_url(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'googleapis.com/upload/drive/*' => Http::response(['id' => 'FILE123']),
            'googleapis.com/drive/v3/files/*/permissions' => Http::response(['id' => 'perm']),
        ]);

        $file = UploadedFile::fake()->createWithContent('menu.png', $this->pngBytes());
        $url = $this->service()->upload($file);

        $this->assertStringContainsString('drive.google.com/uc?export=view&id=FILE123', $url);
        $this->assertStringStartsWith('https://', $url);
    }
}
