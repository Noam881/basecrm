<?php

namespace App\Http\Controllers\Auth;

use App\Auth\MagicLogin;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class MagicLoginController extends Controller
{
    public const SENT_MESSAGE = 'If this email belongs to an active account, a sign-in link is on its way. Check your inbox.';

    public function __construct(private MagicLogin $links, private StatefulGuard $guard) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()?->active && $request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/Login', [
            'status' => $request->session()->get('status'),
            'email' => $request->user()?->email,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => 'required|string|email|max:254']);
        (new Timebox)->call(function () use ($request, $data): void {
            $user = User::where('active', true)->whereRaw('lower(email) = ?', [$data['email']])->first();
            if ($user && (! $request->user() || $request->user()->id === $user->id)) {
                $link = $this->links->issue($user);
                $user->notify(new MagicLoginLink($link['url']));
            }
        }, 200000);

        return redirect()->route('login')->with('status', __(self::SENT_MESSAGE));
    }

    public function show(Request $request, string $token): HttpResponse
    {
        $user = $request->hasValidSignature() ? $this->links->peek($token) : null;
        if ($request->user()?->active && $request->user()->hasVerifiedEmail() && (! $user || $request->user()->id === $user->id)) {
            return redirect()->route('dashboard');
        }
        $valid = $user && (! $request->user() || $request->user()->id === $user->id);

        $response = Inertia::render('auth/ConfirmMagicLogin', [
            'valid' => (bool) $valid,
            'action' => $valid ? $request->fullUrl() : '',
            'email' => $valid ? $user->email : null,
        ])->toResponse($request);
        $response->headers->add([
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);

        return $response;
    }

    public function consume(Request $request, string $token): HttpResponse
    {
        $candidate = $request->hasValidSignature() ? $this->links->peek($token) : null;
        if (! $candidate || ($request->user() && $request->user()->id !== $candidate->id)) {
            return $this->invalid();
        }
        $user = $this->links->consume($token);
        if (! $user) {
            return $this->invalid();
        }
        $request->session()->forget(['login.id', 'login.remember', 'auth.magic_pending', 'auth.password_confirmed_at', 'url.intended']);
        $this->guard->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.method', 'magic_link');

        // Leave the signed document and its no-referrer policy before normal CRM saves.
        return $request->header('X-Inertia')
            ? Inertia::location(route('dashboard'))
            : redirect()->route('dashboard');
    }

    private function invalid(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => __(MagicLogin::INVALID_MESSAGE)]);
    }
}
