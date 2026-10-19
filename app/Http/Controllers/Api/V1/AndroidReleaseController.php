<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AndroidRelease;
use Illuminate\Http\JsonResponse;

/** Lets the Android app check for a newer sideloaded build and point the member at it. */
class AndroidReleaseController extends Controller
{
    public function latest(): JsonResponse
    {
        $release = AndroidRelease::current();
        abort_if($release === null, 404);

        return response()->json([
            'version_name' => $release->version_name,
            'version_code' => $release->version_code,
            'size' => $release->apk_size,
            'sha256' => $release->sha256,
            'notes' => $release->notes,
            'download_url' => route('android.download'),
            'published_at' => $release->created_at->toIso8601String(),
        ]);
    }
}
