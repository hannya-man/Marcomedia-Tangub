<?php

namespace App\Http\Middleware;

// Laravel 13's request forgery protection: a form sent from this site's own pages passes (the
// browser's Sec-Fetch-Site header says same-origin); anything else still needs the CSRF token.
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
