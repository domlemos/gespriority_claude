<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Role::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
