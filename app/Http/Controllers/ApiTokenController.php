<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    public function index()
    {
        $tokens = auth()->user()->tokens()->latest()->get();
        return view('admin.erp.api_tokens', compact('tokens'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'abilities' => ['nullable', 'array'], 'abilities.*' => ['in:accounting:read,accounting:write,inventory:read,inventory:write,sales:read,sales:write,purchasing:read,purchasing:write,warehouse:read,warehouse:write,manufacturing:read,manufacturing:write,service:read,service:write,integration:read,integration:write']]);
        $abilities = array_values(array_unique($data['abilities'] ?? ['accounting:read']));
        $token = $request->user()->createToken($data['name'], $abilities);
        return back()->with(['message' => 'Token created. Copy it now; it will not be shown again.', 'alert-type' => 'success', 'plain_token' => $token->plainTextToken]);
    }

    public function destroy(int $id)
    {
        auth()->user()->tokens()->whereKey($id)->delete();
        return back()->with(['message' => 'API token revoked.', 'alert-type' => 'success']);
    }
}
