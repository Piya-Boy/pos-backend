<?php

namespace App\Http\Controllers\Api;

use App\Pos\Services\AdminService;
use App\Pos\Services\ImageUploadService;
use App\Pos\Services\SettingsService;
use Illuminate\Http\Request;

use function App\Pos\Support\apiOk;

class AdminController
{
    public function __construct(
        private AdminService $admin,
        private SettingsService $settings,
        private ImageUploadService $images,
    ) {}

    private function user(Request $r): array
    {
        return (array) $r->attributes->get('authUser');
    }

    public function data(Request $r)
    {
        return response()->json(apiOk($this->admin->getData($this->user($r))));
    }

    public function settings(Request $r)
    {
        return response()->json(apiOk($this->settings->save((array) $r->input('settings', []))));
    }

    public function entity(Request $r)
    {
        return response()->json(apiOk($this->admin->saveEntity(
            $this->user($r),
            (string) $r->input('entity', ''),
            (array) $r->input('data', []),
        )));
    }

    public function entityArchive(Request $r)
    {
        return response()->json(apiOk($this->admin->archiveEntity(
            $this->user($r),
            (string) $r->input('entity', ''),
            (string) $r->input('id', ''),
        )));
    }

    public function rotateToken(Request $r)
    {
        return response()->json(apiOk($this->admin->rotateToken($this->user($r), (string) $r->input('tableId', ''))));
    }

    public function uploadImage(Request $r)
    {
        return response()->json(apiOk(['url' => $this->images->upload($r->file('image'))]));
    }
}
