<?php

namespace App\Http\Controllers;

use App\Support\LocaleCookie;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $code = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::enabled())],
        ])['locale'];

        $request->user()?->forceFill(['locale' => $code])->save();

        return redirect()->to(url()->previous('/'), 303)->withCookie(LocaleCookie::make($code));
    }
}
