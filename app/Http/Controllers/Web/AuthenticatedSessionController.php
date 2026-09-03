<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connexion par SESSION pour l'UI (mode SPA Sanctum : le cookie de session
 * authentifie ensuite les appels /api/*). Distincte de POST /api/auth/login
 * (token, pour les clients programmatiques). Journalise les tentatives dans
 * activity_logs, comme le middleware RecordActivity le fait pour l'API.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = 'login:'.Str::lower((string) $request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives de connexion. Réessayez dans une minute.',
            ]);
        }

        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            $this->log($request, null, 422);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $this->log($request, Auth::id(), 200);

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $userId = Auth::id();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->log($request, $userId, 200);

        return redirect('/login');
    }

    private function log(Request $request, ?int $userId, int $status): void
    {
        ActivityLog::create([
            'user_id' => $userId,
            'method' => $request->getMethod(),
            'route' => $request->route()?->getName() ?: $request->path(),
            'ip' => $request->ip(),
            'payload_digest' => null,
            'status_code' => $status,
            'created_at' => now(),
        ]);
    }
}
