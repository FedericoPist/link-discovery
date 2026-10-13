<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403, 'Questo workspace è disponibile solo in locale.');
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
