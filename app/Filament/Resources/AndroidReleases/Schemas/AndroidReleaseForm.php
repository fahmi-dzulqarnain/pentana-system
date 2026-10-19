<?php

namespace App\Filament\Resources\AndroidReleases\Schemas;

use App\Models\AndroidRelease;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AndroidReleaseForm
{
    /** Matches the Livewire temporary upload limit in config/livewire.php. */
    public const MAX_APK_KILOBYTES = 51200;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('apk_path')
                    ->label(__('APK file'))
                    ->disk(AndroidRelease::DISK)
                    ->directory('android')
                    ->visibility('private')
                    ->required()
                    ->maxSize(self::MAX_APK_KILOBYTES)
                    // Browsers report an APK as zip or octet-stream, so check the extension, not the MIME type.
                    ->rules(['extensions:apk'])
                    ->extraInputAttributes(['accept' => '.apk'])
                    ->helperText(__('The signed release build, up to 50 MB.'))
                    ->columnSpanFull(),
                TextInput::make('version_name')
                    ->label(__('Version name'))
                    ->placeholder('1.0.1')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^[0-9A-Za-z.\-]+$/'),
                TextInput::make('version_code')
                    ->label(__('Version code'))
                    ->integer()
                    ->required()
                    ->minValue(fn () => (AndroidRelease::max('version_code') ?? 0) + 1)
                    ->default(fn () => (AndroidRelease::max('version_code') ?? 0) + 1)
                    ->helperText(__('Must match versionCode in the build and be higher than every earlier release.')),
                Textarea::make('notes')
                    ->label(__('What\'s new'))
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
