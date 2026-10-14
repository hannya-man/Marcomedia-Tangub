<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

// Confirming an email happens in Profile, where it's explained (no page of its own).
class EmailVerificationPromptController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('profile.edit');
    }
}
