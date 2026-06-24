<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePaymentProofRequest;
use App\Http\Resources\Api\PaymentProofResource;
use App\Models\Payment\PaymentProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentProofController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PaymentProofResource::collection(
            $request->user()->paymentProofs()->latest()->get()
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
}
