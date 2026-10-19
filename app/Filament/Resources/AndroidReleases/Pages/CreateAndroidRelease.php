<?php

namespace App\Filament\Resources\AndroidReleases\Pages;

use App\Filament\Resources\AndroidReleases\AndroidReleaseResource;
use App\Models\AndroidRelease;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateAndroidRelease extends CreateRecord
{
    protected static string $resource = AndroidReleaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $absolutePath = Storage::disk(AndroidRelease::DISK)->path($data['apk_path']);

        return [
            ...$data,
            'apk_size' => filesize($absolutePath),
            'sha256' => hash_file('sha256', $absolutePath),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
