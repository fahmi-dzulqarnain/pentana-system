<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BillResource;
use App\Models\Payment\FeePeriod;
use App\Services\FinanceService;
use App\Support\PaymentDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return BillResource::collection(
            FeePeriod::forMember($request->user()->id)->get()
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $bills = FeePeriod::forMember($request->user()->id)->get();

        $totalOutstanding = FinanceService::outstanding($bills);

        return response()->json([
            'data' => [
                'total_outstanding' => number_format(round($totalOutstanding, 2), 2, '.', ''),
                'available_credit' => number_format((float) $request->user()->credit, 2, '.', ''),
                'unpaid_count' => $bills->where('status', '!=', 'paid')->count(),
                'bill_count' => $bills->count(),
                // Where to transfer, so the app's Bills tab and proof sheet can show it.
                'payment_details' => PaymentDetails::for($request->user()),
            ],
        ]);
    }
}
