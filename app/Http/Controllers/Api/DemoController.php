<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProvisionOrganization;
use App\Http\Controllers\Controller;
use App\Services\Demo\DemoDataGenerator;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Public "try it" endpoint: spins up a private, sample-loaded, throwaway
 * organization with an admin, signs them in, and expires in one hour. The
 * route is rate-limited; expiry is enforced by BlockExpiredDemo and the rows
 * are purged by the demos:purge command.
 */
class DemoController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function store(Request $request, ProvisionOrganization $provision): JsonResponse
    {
        $tag = Str::lower(Str::random(6));

        $org = $provision->create(
            "Demo Workspace {$tag}",
            [
                'name' => 'Demo User',
                'email' => "demo-{$tag}@demo.local",
                'password' => Str::password(24), // internal — the user is auto-signed-in
                'must_change_password' => false,
            ],
            null,
            ['is_demo' => true, 'expires_at' => now()->addHour()],
        );

        $admin = $org->users()->firstOrFail();

        // Sample sources/snapshots so the dashboard is alive (lighter 7-day window).
        $this->tenancy->runFor($org->id, fn () => app(DemoDataGenerator::class)->generateFor($admin, 7));

        // Auto sign-in.
        Auth::login($admin);
        $request->session()->regenerate();

        return response()->json(['user' => AuthController::userPayload($admin->fresh())]);
    }
}
