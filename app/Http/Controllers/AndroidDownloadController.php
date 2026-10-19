<?php

namespace App\Http\Controllers;

use App\Models\AndroidRelease;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AndroidDownloadController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $release = AndroidRelease::current();
        abort_if($release === null, 404);

        return Storage::disk(AndroidRelease::DISK)->download($release->apk_path, $release->downloadName(), [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
