<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiSource;
use App\Models\User;
use App\Services\Security\TechStackAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __construct(private TechStackAuditor $stack) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'time' => now(),
            ],
            'database' => $this->database(),
            'cache' => $this->cache(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'sources' => $this->sources(),
            'security' => $this->security(),
        ]);
    }

    public function techStack(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $force = $request->boolean('refresh');
        return response()->json($this->stack->snapshot($force));
    }

    private function database(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return ['ok' => true, 'latency_ms' => round((microtime(true) - $start) * 1000, 1), 'driver' => DB::connection()->getDriverName()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function cache(): array
    {
        try {
            Cache::put('health:ping', '1', 5);

            return ['ok' => Cache::get('health:ping') === '1', 'store' => config('cache.default')];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function queue(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            return ['ok' => $failed === 0, 'pending' => $pending, 'failed' => $failed, 'connection' => config('queue.default')];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function scheduler(): array
    {
        $last = Cache::get('scheduler.last_run');
        $stale = ! $last || now()->diffInMinutes($last) > 10;

        return [
            'ok' => ! $stale,
            'last_run' => $last,
            'stale' => $stale,
            'hint' => $stale ? 'Run "php artisan schedule:work" (or a cron entry) to enable scheduled refreshes.' : null,
        ];
    }

    private function sources(): array
    {
        $sources = ApiSource::query()->get(['is_enabled', 'last_status', 'last_run_at', 'refresh_interval_minutes', 'secret_mode']);

        $stale = $sources->filter(function ($s) {
            if (! $s->is_enabled || ! $s->last_run_at) {
                return false;
            }

            return now()->diffInMinutes($s->last_run_at) > $s->refresh_interval_minutes * 2;
        })->count();

        return [
            'total' => $sources->count(),
            'enabled' => $sources->where('is_enabled', true)->count(),
            'failing' => $sources->where('last_status', 'failed')->count(),
            'stale' => $stale,
            'per_login' => $sources->where('secret_mode', ApiSource::SECRET_PER_LOGIN)->count(),
        ];
    }

    private function security(): array
    {
        $total = User::count();
        $mfa = User::where('mfa_enabled', true)->count();

        return [
            'users' => $total,
            'mfa_enabled' => $mfa,
            'mfa_adoption_pct' => $total > 0 ? round($mfa / $total * 100) : 0,
            'flagged_ips' => User::where('ip_flagged', true)->count(),
            'locked' => User::whereNotNull('locked_until')->where('locked_until', '>', now())->count(),
            'online' => User::where('last_seen_at', '>', now()->subMinutes((int) config('security.online_window_minutes', 5)))->count(),
        ];
    }
}
