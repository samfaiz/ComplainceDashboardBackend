<?php

namespace App\Actions;

use App\Models\MailSettings;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\NotificationCatalog;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * Creates a fully-provisioned organization: its starter notification template
 * set, an (empty) mail settings row, and a first admin user — all stamped to
 * the new org. Used by the platform owner and the database seeder.
 */
class ProvisionOrganization
{
    public function __construct(private Tenancy $tenancy) {}

    /**
     * @param  array{name:string,email:string,password:string,must_change_password?:bool}  $admin
     * @param  array<string,mixed>  $orgAttributes  extra Organization columns (e.g. is_demo, expires_at)
     */
    public function create(string $name, array $admin, ?User $creator = null, array $orgAttributes = []): Organization
    {
        $org = Organization::create(array_merge([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'is_active' => true,
            'created_by_user_id' => $creator?->id,
        ], $orgAttributes));

        $this->tenancy->runFor($org->id, function () use ($org, $admin) {
            $this->seedTemplates();
            MailSettings::forOrganization($org->id);

            $user = new User([
                'organization_id' => $org->id,
                'name' => $admin['name'],
                'email' => $admin['email'],
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]);
            $user->password = $admin['password']; // 'hashed' cast handles hashing
            $user->must_change_password = $admin['must_change_password'] ?? true;
            $user->save();
        });

        return $org->fresh();
    }

    /** Seed the standard notification template set for the current org context. */
    public function seedTemplates(): void
    {
        foreach (NotificationCatalog::events() as $event) {
            NotificationTemplate::updateOrCreate(
                ['event_key' => $event['event_key']],
                [
                    'display_name' => $event['display_name'],
                    'category' => $event['category'],
                    'default_severity' => $event['default_severity'],
                    'subject' => $event['subject'],
                    'body_html' => $event['body_html'],
                    'body_text' => $event['body_text'] ?? null,
                    'enabled' => true,
                ]
            );
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
