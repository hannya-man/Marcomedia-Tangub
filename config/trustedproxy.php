<?php

return [

    // Proxies whose X-Forwarded-* headers are believed (App\Http\Middleware\TrustProxies falls back
    // to this). On Vercel every request reaches PHP through Vercel's proxy: "*" keeps links on https
    // and the visitor's own address for the login limit. Unset (the PC), none are trusted.
    'proxies' => env('TRUSTED_PROXIES'),

];
