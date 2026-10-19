<?php

// Only the keys that differ from Livewire's defaults. Livewire merges this file one level
// deep, so temporary_file_upload has to be complete, not just the changed rule.
return [

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Raised from 12 MB so admins can upload Android APKs (AndroidReleaseForm::MAX_APK_KILOBYTES).
        // Each field still applies its own, smaller limit when the form is saved.
        'rules' => ['required', 'file', 'max:51200'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
