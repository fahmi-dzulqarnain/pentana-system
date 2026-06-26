<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ActivityResource;
use App\Models\Activity;
use App\Services\ActivityRegistrationService;
use App\Services\RegistrationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $activities = Activity::with('registrations')
            ->where('status', 'published')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        return ActivityResource::collection($activities);
    }

    public function register(Request $request, Activity $activity): JsonResponse
    {
        $fields = $activity->registration_fields ?? [];

        // Enforce the activity's required questions (mirrors the web register flow).
        $rules = [];
        foreach ($fields as $field) {
            if (! empty($field['required'])) {
                $rules["answers.{$field['key']}"] = ($field['type'] ?? 'text') === 'checkbox' ? 'accepted' : 'required';
            }
        }
        $request->validate($rules);

        // Keep only answers to defined questions (the body is client-controlled).
        $answers = collect($request->input('answers', []))->only(array_column($fields, 'key'))->all();

        try {
            app(ActivityRegistrationService::class)->register($activity, $request->user(), $answers);
        } catch (RegistrationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->fresh($activity);
    }

    public function cancel(Request $request, Activity $activity): JsonResponse
    {
        app(ActivityRegistrationService::class)->cancel($activity, $request->user());

        return $this->fresh($activity);
    }

    private function fresh(Activity $activity): JsonResponse
    {
        return (new ActivityResource($activity->load('registrations')))->response();
    }
}
