<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiSource;
use App\Services\Connectors\VendorPresets;
use App\Services\Ingest\IngestService;
use App\Services\Ingest\SourceTester;
use App\Services\Security\AuditLogger;
use App\Services\Security\SessionSecretVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApiSourceController extends Controller
{
    public function __construct(
        private SourceTester $tester,
        private IngestService $ingest,
        private SessionSecretVault $vault,
        private AuditLogger $audit,
    ) {}

    /** Vendor connection profiles for the setup wizard. */
    public function presets(): JsonResponse
    {
        return response()->json([
            'presets' => array_values(VendorPresets::all()),
            'refresh_intervals' => config('security.refresh_intervals'),
        ]);
    }

    /** Validate a connection (capped pull) and preview the normalized output. */
    public function test(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, requireSecret: true);
        $source = $this->makeTransient($request, $data);

        return response()->json($this->tester->test($source, $data['secret']));
    }

    public function index(Request $request): JsonResponse
    {
        $sources = $request->user()->apiSources()->latest()->get();

        return response()->json([
            'sources' => $sources->map(fn ($s) => $this->payload($s))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $saved = $request->input('secret_mode') === ApiSource::SECRET_SAVED;
        $data = $this->validatePayload($request, requireSecret: $saved);

        $source = new ApiSource($this->attributes($data));
        $source->user_id = $request->user()->id;

        if ($saved && ! empty($data['secret'])) {
            $source->storeSecret($data['secret']);
        }

        $source->save();

        // Stash a per-login secret for this session so an immediate refresh works.
        if (! $saved && ! empty($data['secret'])) {
            $this->vault->put((int) $source->id, $data['secret']);
        }

        $this->audit->log('source.created', $request->user(), $source, [
            'vendor' => $source->vendor,
            'secret_mode' => $source->secret_mode,
        ]);

        return response()->json(['source' => $this->payload($source)], 201);
    }

    public function show(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        return response()->json(['source' => $this->payload($source)]);
    }

    public function update(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        $saved = ($request->input('secret_mode', $source->secret_mode)) === ApiSource::SECRET_SAVED;
        $data = $this->validatePayload($request, requireSecret: false);

        $source->fill($this->attributes($data));

        // Only replace the secret if a new one was supplied.
        if ($saved && ! empty($data['secret'])) {
            $source->storeSecret($data['secret']);
        } elseif (! $saved) {
            $source->storeSecret(null); // switching to per-login clears any stored secret
            if (! empty($data['secret'])) {
                $this->vault->put((int) $source->id, $data['secret']);
            }
        }

        $source->save();
        $this->audit->log('source.updated', $request->user(), $source);

        return response()->json(['source' => $this->payload($source)]);
    }

    public function destroy(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);
        $this->vault->forget((int) $source->id);
        $this->audit->log('source.deleted', $request->user(), $source, ['name' => $source->name]);
        $source->delete();

        return response()->json(['message' => 'Source deleted.']);
    }

    /** Provide a per-login secret for this session (does not persist it). */
    public function unlock(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);
        $data = $request->validate(['secret' => ['required', 'string']]);

        $this->vault->put((int) $source->id, $data['secret']);

        return response()->json(['unlocked' => true]);
    }

    /** Trigger an immediate refresh (manual pull). */
    public function refresh(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        $secret = $this->resolveSecret($source);
        if ($secret === null) {
            throw ValidationException::withMessages([
                'secret' => ['This source needs its API key for this session. Unlock it first.'],
            ]);
        }

        $run = $this->ingest->run($source, $secret, 'manual');
        $this->audit->log('source.refreshed', $request->user(), $source, ['status' => $run->status]);

        return response()->json([
            'run' => [
                'status' => $run->status,
                'records_ingested' => $run->records_ingested,
                'error' => $run->error_message,
                'duration_ms' => $run->duration_ms,
            ],
            'source' => $this->payload($source->fresh()),
        ]);
    }

    /** Recent run history for a source. */
    public function runs(Request $request, ApiSource $source): JsonResponse
    {
        $this->authorizeSource($request, $source);

        return response()->json([
            'runs' => $source->runs()->latest()->limit(25)->get(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function resolveSecret(ApiSource $source): ?string
    {
        if ($source->usesSavedSecret()) {
            return $source->revealSecret();
        }

        return $this->vault->get((int) $source->id);
    }

    private function authorizeSource(Request $request, ApiSource $source): void
    {
        abort_unless(
            $source->user_id === $request->user()->id || $request->user()->isAdmin(),
            403,
            'You do not have access to this source.'
        );
    }

    private function validatePayload(Request $request, bool $requireSecret): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'site_id' => ['nullable', 'integer'],
            'vendor' => ['required', Rule::in(ApiSource::VENDORS)],
            'base_url' => ['required', 'string', 'max:255'],
            'auth_type' => ['required', Rule::in([
                ApiSource::AUTH_BEARER, ApiSource::AUTH_API_KEY_HEADER,
                ApiSource::AUTH_BASIC, ApiSource::AUTH_OAUTH2_CC,
            ])],
            'auth_config' => ['nullable', 'array'],
            'request_config' => ['nullable', 'array'],
            'field_mappings' => ['nullable', 'array'],
            'refresh_interval_minutes' => ['required', 'integer', 'min:5', 'max:10080'],
            'secret_mode' => ['required', Rule::in([ApiSource::SECRET_SAVED, ApiSource::SECRET_PER_LOGIN])],
            'secret' => [$requireSecret ? 'required' : 'nullable', 'string'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        // The site (if any) must belong to the user.
        if (! empty($data['site_id']) && ! $request->user()->sites()->whereKey($data['site_id'])->exists()) {
            abort(422, 'Invalid site.');
        }

        return $data;
    }

    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'site_id' => $data['site_id'] ?? null,
            'vendor' => $data['vendor'],
            'base_url' => $data['base_url'],
            'auth_type' => $data['auth_type'],
            'auth_config' => $data['auth_config'] ?? [],
            'request_config' => $data['request_config'] ?? [],
            'field_mappings' => $data['field_mappings'] ?? [],
            'refresh_interval_minutes' => $data['refresh_interval_minutes'],
            'secret_mode' => $data['secret_mode'],
            'is_enabled' => $data['is_enabled'] ?? true,
        ];
    }

    private function makeTransient(Request $request, array $data): ApiSource
    {
        $source = new ApiSource($this->attributes($data));
        $source->user_id = $request->user()->id;
        $source->id = 0;

        return $source;
    }

    private function payload(ApiSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'site_id' => $source->site_id,
            'vendor' => $source->vendor,
            'base_url' => $source->base_url,
            'auth_type' => $source->auth_type,
            'auth_config' => $source->auth_config,
            'request_config' => $source->request_config,
            'field_mappings' => $source->field_mappings,
            'refresh_interval_minutes' => $source->refresh_interval_minutes,
            'secret_mode' => $source->secret_mode,
            'secret_hint' => $source->secret_hint,
            'has_secret' => $source->usesSavedSecret()
                ? ! empty($source->secret_encrypted)
                : $this->vault->has((int) $source->id),
            'requires_unlock' => $source->requiresSecretEachLogin() && ! $this->vault->has((int) $source->id),
            'is_enabled' => $source->is_enabled,
            'last_run_at' => $source->last_run_at,
            'last_status' => $source->last_status,
            'last_error' => $source->last_error,
            'latest_snapshot_id' => $source->latest_snapshot_id,
        ];
    }
}
