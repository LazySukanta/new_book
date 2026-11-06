<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Branch-Id');
        abort_unless(is_string($id) && ctype_digit($id), 403, 'Select an authorized branch.');
        $branch = $request->user()->accessibleBranches()->findOrFail($id);
        if ($device = $request->attributes->get('device')) {
            abort_unless($device->branches()->whereKey($branch->id)->exists(), 404);
        }
        $request->attributes->set('branch', $branch);

        return $next($request);
    }
}
