<?php

namespace App\Http\Controllers;

use App\Models\Lunch;
use App\Services\LunchVoteBreakdown;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Standalone print page of who voted what, for an admin to tick off at pickup. */
class LunchPrintController extends Controller
{
    public function __invoke(Request $request, Lunch $lunch): View
    {
        abort_unless($request->user()->is_admin, 403);

        return view('lunches.print', [
            'lunch' => $lunch,
            'breakdown' => LunchVoteBreakdown::for($lunch),
        ]);
    }
}
