<?php

namespace Tests\Feature;

use App\Models\NotebookDocument;
use App\Models\Setting;
use App\Services\Modules;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();
        $this->assertSame('carevma_test', $database, "Tests must run against carevma_test, not [{$database}].");

        Mail::fake();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@carevma.test', 'password' => 'password', 'role' => 'admin']);
        $this->hr = User::create(['name' => 'Helen', 'email' => 'hr@carevma.test', 'password' => 'password', 'role' => 'hr']);
    }

    public function test_hr_starts_with_its_classic_modules_and_nothing_else(): void
    {
        $me = $this->actingAs($this->hr)->getJson('/api/me')->assertOk()->json('user.permissions');
        $this->assertEqualsCanonicalizing(['live-users', 'employees', 'schedules', 'attendance', 'change-requests', 'overtime', 'notebook', 'configuration'], $me);

        $this->actingAs($this->hr)->getJson('/api/management/employees')->assertOk();
        $this->actingAs($this->hr)->getJson('/api/admin/clients')->assertStatus(403)->assertJsonPath('message', 'You do not have access to the Clients module.');
        $this->actingAs($this->hr)->getJson('/api/admin/invoices')->assertStatus(403);
        $this->actingAs($this->hr)->getJson('/api/admin/roles')->assertStatus(403);
    }


    public function test_notebook_download_respects_the_same_module_grant_as_the_notebook_page(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('notebook/shared/99/guide.txt', 'CareVMA guide');

        $recipient = User::create([
            'name' => 'VA Recipient',
            'email' => 'recipient@carevma.test',
            'password' => 'password',
            'role' => 'employee',
        ]);

        $document = NotebookDocument::create([
            'user_id' => $this->admin->id,
            'recipient_user_id' => $recipient->id,
            'title' => 'Guide',
            'file_path' => 'notebook/shared/99/guide.txt',
            'storage_disk' => 'local',
            'original_name' => 'guide.txt',
            'mime_type' => 'text/plain',
            'file_size' => 12,
            'shared_at' => now(),
        ]);

        // HR has Notebook by default and may download managed documents.
        $this->actingAs($this->hr)
            ->get('/api/shared-documents/'.$document->id.'/download')
            ->assertOk();

        // Revoking Notebook must also revoke direct download-by-ID access.
        $this->actingAs($this->admin)
            ->putJson('/api/admin/roles/hr', ['modules' => ['employees']])
            ->assertOk();

        $this->actingAs($this->hr)
            ->get('/api/shared-documents/'.$document->id.'/download')
            ->assertStatus(403);

        // The intended recipient keeps access regardless of staff module grants.
        $this->actingAs($recipient)
            ->get('/api/shared-documents/'.$document->id.'/download')
            ->assertOk();
    }

    public function test_admin_can_grant_and_revoke_modules_for_a_role(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/roles')->assertOk()
            ->assertJsonPath('roles.0.key', 'hr')
            ->assertJsonFragment(['key' => 'clients', 'label' => 'Clients', 'group' => 'People']);

        $this->actingAs($this->admin)->putJson('/api/admin/roles/hr', ['modules' => ['employees', 'clients', 'bogus']])
            ->assertStatus(422)->assertJsonValidationErrors(['modules.2']);
        $this->actingAs($this->admin)->putJson('/api/admin/roles/employee', ['modules' => ['clients']])->assertStatus(404);

        $this->actingAs($this->admin)->putJson('/api/admin/roles/hr', ['modules' => ['employees', 'clients']])
            ->assertOk()->assertJsonPath('grants.hr', ['employees', 'clients']);

        // Granted: the clients API opens, and the VA's own session carries the new grant.
        $this->actingAs($this->hr)->getJson('/api/admin/clients')->assertOk();
        $this->assertEqualsCanonicalizing(['employees', 'clients'], $this->actingAs($this->hr)->getJson('/api/me')->json('user.permissions'));

        // Revoked: schedules are gone, even though HR had them by default.
        $this->actingAs($this->hr)->getJson('/api/management/schedules')->assertStatus(403);

        // Admins are never limited by the matrix.
        $this->actingAs($this->admin)->getJson('/api/management/schedules')->assertOk();
        $this->assertCount(count(Modules::all()), $this->actingAs($this->admin)->getJson('/api/me')->json('user.permissions'));
    }

    public function test_legacy_grants_are_retained_and_actions_can_be_restricted(): void
    {
        Setting::put(Modules::KEY, ['hr' => ['company-finance']]);
        $this->actingAs($this->admin)->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonPath('action_grants.hr', ['company-finance.create', 'company-finance.delete'])
            ->assertJsonPath('locked_modules.roles-permissions', 'admin');

        $this->putJson('/api/admin/roles/hr', [
            'modules' => ['company-finance', 'branding', 'client-services'],
            'actions' => ['company-finance.create'],
        ])->assertOk();
        $this->putJson('/api/admin/roles/hr', [
            'modules' => ['company-finance'],
            'actions' => ['branding.edit'],
        ])->assertUnprocessable()->assertJsonValidationErrors('actions');

        // Existing clients that only send module keys keep the saved action restrictions.
        $this->putJson('/api/admin/roles/hr', [
            'modules' => ['company-finance', 'branding', 'client-services'],
        ])->assertOk()->assertJsonPath('action_grants.hr', ['company-finance.create']);

        $this->actingAs($this->hr)->getJson('/api/me')->assertJsonPath('user.action_permissions', ['company-finance.create']);
        $this->getJson('/api/admin/company-finance')->assertOk();
        $entryId = $this->postJson('/api/admin/company-finance/entries', [
            'entry_date' => '2026-11-20', 'type' => 'income', 'category' => 'Test', 'amount' => 25,
        ])->assertCreated()->json('entry.id');
        $this->deleteJson("/api/admin/company-finance/entries/{$entryId}")->assertForbidden();
        $this->getJson('/api/settings/branding')->assertOk();
        $this->putJson('/api/settings/branding', ['company_name' => 'New company'])->assertForbidden();
        $this->getJson('/api/management/references/services')->assertOk();
        $this->postJson('/api/management/references/services', ['name' => 'Test service'])->assertForbidden();
        $this->getJson('/api/admin/invoice-settings')->assertForbidden();
    }
}
