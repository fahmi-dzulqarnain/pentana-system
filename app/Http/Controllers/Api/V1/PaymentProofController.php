<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePaymentProofRequest;
use App\Http\Resources\Api\PaymentProofResource;
use App\Models\Payment\PaymentProof;
use App\Services\ReceiptPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PaymentProofController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaymentProofResource::collection(
            $request->user()->paymentProofs()->with('receipt')->latest()->get()
        );
    }

    public function store(StorePaymentProofRequest $request): JsonResponse
    {
        $path = $request->file('proof_image')->store('payment-proofs', 'public');

        $proof = PaymentProof::create([
            'user_id' => $request->user()->id,
            'proof_image_path' => $path,
            'amount_claimed' => $request->validated('amount_claimed'),
            'member_note' => $request->validated('member_note'),
            'status' => 'pending', // admin reviews + verifies on the web
        ]);

        return (new PaymentProofResource($proof))->response()->setStatusCode(201);
    }

    /**
     * Owner-only receipt download. Anyone else (finance admins included, this
     * endpoint serves members' own apps) gets 404 so existence isn't leaked.
     */
    public function receipt(Request $request, PaymentProof $paymentProof): Response
    {
        abort_unless($paymentProof->user_id === $request->user()->id, 404);

        $receipt = $paymentProof->receipt;
        abort_if($receipt === null, 404);

        return response(app(ReceiptPdf::class)->render($receipt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$receipt->filename().'"',
        ]);
    }
}
