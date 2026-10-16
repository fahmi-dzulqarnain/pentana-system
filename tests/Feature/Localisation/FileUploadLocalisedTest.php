<?php

namespace Tests\Feature\Localisation;

use Filament\Forms\Components\FileUpload;
use Tests\TestCase;

class FileUploadLocalisedTest extends TestCase
{
    /** FilePond ships no Malay locale, so its English idle label leaked into the ms UI. */
    public function test_upload_prompt_follows_the_app_language(): void
    {
        app()->setLocale('ms');

        $placeholder = FileUpload::make('proof')->getPlaceholder();

        $this->assertStringNotContainsString('Drag & Drop', $placeholder);
        $this->assertStringContainsString(__('Browse', [], 'ms'), $placeholder);
    }
}
