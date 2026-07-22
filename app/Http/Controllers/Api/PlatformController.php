<?php

namespace App\Http\Controllers\Api;

use App\Actions\ProvisionOrganization;
use App\Http\Controllers\Controller;
use App\Models\ApiSource;
use App\Models\MailSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\MailConfigurator;
use App\Services\Security\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Platform-owner (super_admin) cross-organization management. These routes run
 * without an org scope; the platform owner may "enter" an org to view/manage it
 * scoped (the entered org id lives in the session and is read by SetTenant).
 */
class PlatformController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private MailConfigurator $mailConfigurator,
    ) {}

    public function organizations(): JsonResponse
    {
        $orgs = Organization::orderBy('name')->get()->map(fn (Organization $o) => $this->payload($o));

        return response()->json(['organizations' => $orgs]);
    }

    public function store(Request $request, ProvisionOrganization $provision): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:200', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:12'],
        ]);

        $org = $provision->create($data['name'], [
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => $data['admin_password'],
            'must_change_password' => true,
        ], $request->user());

        $this->audit->log('platform.org_created', $request->user(), $org, ['name' => $org->name]);

        return response()->json(['organization' => $this->payload($org)], 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        return response()->json(['organization' => $this->payload($organization)]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $organization->fill($data)->save();
        $this->audit->log('platform.org_updated', $request->user(), $organization, $data);

        return response()->json(['organization' => $this->payload($organization->fresh())]);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $name = $organization->name;

        // Remove members first — their FK is nullOnDelete, which would otherwise
        // orphan them; their owned data cascades. Remaining org-stamped rows
        // cascade when the organization itself is deleted.
        User::withoutOrganizationScope()->where('organization_id', $organization->id)->delete();
        $organization->delete();

        $this->audit->log('platform.org_deleted', $request->user(), null, ['name' => $name]);

        return response()->json(['message' => 'Organization deleted.']);
    }

    /** Enter an org — subsequent requests are scoped to it (see SetTenant). */
    public function enter(Request $request, Organization $organization): JsonResponse
    {
        $request->session()->put('platform.organization_id', $organization->id);

        return response()->json(['entered' => ['id' => $organization->id, 'name' => $organization->name]]);
    }

    public function exit(Request $request): JsonResponse
    {
        $request->session()->forget('platform.organization_id');

        return response()->json(['exited' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* Platform email integration (SMTP + where notifications are sent).  */
    /* ------------------------------------------------------------------ */

    public function mailSettings(): JsonResponse
    {
        return response()->json(['settings' => $this->serializeMail(MailSettings::platform())]);
    }

    public function updateMailSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transport' => ['required', 'in:smtp,log'],
            'host' => ['nullable', 'string', 'max:200'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'in:tls,ssl,none'],
            'username' => ['nullable', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['nullable', 'email', 'max:200'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'notify_email' => ['nullable', 'email', 'max:200'],
            'enabled' => ['required', 'boolean'],
        ]);

        $settings = MailSettings::platform();
        $settings->fill([
            'transport' => $data['transport'],
            'host' => $data['host'] ?? null,
            'port' => $data['port'] ?? null,
            'encryption' => ($data['encryption'] ?? null) === 'none' ? null : ($data['encryption'] ?? null),
            'username' => $data['username'] ?? null,
            'from_address' => $data['from_address'] ?? null,
            'from_name' => $data['from_name'] ?? null,
            'notify_email' => $data['notify_email'] ?? null,
            'enabled' => $data['enabled'],
        ]);

        // Only overwrite the password when a value is supplied (empty = keep existing).
        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $settings->setPassword($data['password']);
        }
        $settings->save();

        $this->mailConfigurator->applyPlatform();
        $this->audit->log('platform.mail_settings_updated', $request->user(), $settings, [
            'transport' => $settings->transport, 'enabled' => $settings->enabled,
        ]);

        return response()->json(['settings' => $this->serializeMail($settings)]);
    }

    public function testMail(Request $request): JsonResponse
    {
        $settings = MailSettings::platform();
        $to = $request->input('to') ?: $settings->notify_email;

        $request->merge(['to' => $to]);
        $data = $request->validate(['to' => ['required', 'email', 'max:200']]);

        $this->mailConfigurator->applyPlatform();

        try {
            Mail::raw(
                "This is a test email from the EDR Compliance Dashboard platform.\nSent at ".now()->toDateTimeString(),
                fn ($m) => $m->to($data['to'])->subject('[Platform] Test email — EDR Compliance')
            );
            $settings->forceFill([
                'last_test_at' => now(), 'last_test_status' => 'ok', 'last_test_error' => null,
            ])->save();

            return response()->json(['message' => "Test email sent to {$data['to']}."]);
        } catch (Throwable $e) {
            $settings->forceFill([
                'last_test_at' => now(), 'last_test_status' => 'failed',
                'last_test_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return response()->json(['message' => 'Test failed: '.$e->getMessage()], 422);
        }
    }

    private function serializeMail(MailSettings $s): array
    {
        return [
            'transport' => $s->transport,
            'host' => $s->host,
            'port' => $s->port,
            'encryption' => $s->encryption,
            'username' => $s->username,
            'has_password' => $s->hasPassword(),
            'from_address' => $s->from_address,
            'from_name' => $s->from_name,
            'notify_email' => $s->notify_email,
            'enabled' => $s->enabled,
            'last_test_at' => $s->last_test_at,
            'last_test_status' => $s->last_test_status,
            'last_test_error' => $s->last_test_error,
        ];
    }

    private function payload(Organization $o): array
    {
        return [
            'id' => $o->id,
            'name' => $o->name,
            'slug' => $o->slug,
            'is_active' => $o->is_active,
            'created_at' => $o->created_at,
            'users_count' => User::withoutOrganizationScope()->where('organization_id', $o->id)->count(),
            'sources_count' => ApiSource::withoutOrganizationScope()->where('organization_id', $o->id)->count(),
        ];
    }
}
