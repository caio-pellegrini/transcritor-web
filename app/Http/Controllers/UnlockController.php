<?php

namespace App\Http\Controllers;

use App\Actions\VerifyGlobalPassword;
use App\Http\Requests\UnlockRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnlockController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('transcription_unlocked') === true) {
            return to_route('transcriptions.index');
        }

        return Inertia::render('unlock');
    }

    public function store(
        UnlockRequest $request,
        VerifyGlobalPassword $verifyGlobalPassword,
    ): RedirectResponse {
        $password = $request->validated('password');

        if (! $verifyGlobalPassword->handle($password)) {
            return back()->withErrors([
                'password' => 'Senha incorreta.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('transcription_unlocked', true);

        return to_route('transcriptions.index');
    }
}
