<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Semua request ke /api diperlakukan sebagai klien JSON, sehingga error
 * (401, 404, 422) selalu dikembalikan sebagai JSON, bukan redirect ke halaman login.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
