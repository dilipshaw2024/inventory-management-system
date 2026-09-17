<?php

namespace App\Http\Controllers;

use App\Models\UserSession;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserSessionController extends Controller
{
    public function index(Request $request)
    {
        $currentHash = hash('sha256', $request->session()->getId());
        $sessions = UserSession::where('user_id', Auth::id())->latest('last_activity')->get();
        return view('admin.user_sessions', compact('sessions', 'currentHash'));
    }

    public function revoke(Request $request, int $id)
    {
        $session = UserSession::where('user_id', Auth::id())->findOrFail($id);
        $currentHash = hash('sha256', $request->session()->getId());
        $session->update(['revoked_at' => now()]);
        app(AuditService::class)->record('user_session.revoked', $session, null, ['revoked_at' => now()->toISOString()]);
        if ($session->session_hash === $currentHash) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->with(['message' => 'Your session was revoked.', 'alert-type' => 'info']);
        }
        return back()->with(['message' => 'Session revoked.', 'alert-type' => 'success']);
    }
}
