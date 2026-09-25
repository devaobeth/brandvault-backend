<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Workspace;
use Illuminate\Http\Request;

trait ResolvesWorkspace
{
    protected function workspace(Request $request): Workspace
    {
        $workspace = $request->user()->workspace;

        if (blank($workspace)) {
            abort(500, 'Workspace not found for this user.');
        }

        return $workspace;
    }
}
