<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An Android APK published for sideloading while there is no Play Store listing.
 * The file lives on the private `local` disk (the repo is public, so never in git)
 * and is served by the download route; the highest version code is the current one.
 */
class AndroidRelease extends Model
{
    public const DISK = 'local';

    protected $fillable = ['version_name', 'version_code', 'apk_path', 'apk_size', 'sha256', 'notes'];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'apk_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (AndroidRelease $release) => Storage::disk(self::DISK)->delete($release->apk_path));
    }

    public static function current(): ?self
    {
        return static::query()->orderByDesc('version_code')->first();
    }

    public function downloadName(): string
    {
        return 'pentana-'.$this->version_name.'.apk';
    }

    public function sizeInMegabytes(): string
    {
        return number_format($this->apk_size / 1048576, 1);
    }
}
