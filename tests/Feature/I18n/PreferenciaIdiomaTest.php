<?php

namespace Tests\Feature\I18n;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferenciaIdiomaTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string ...$permissions): array
    {
        $user = User::factory()->create();

        if ($permissions !== []) {
            $role = Role::factory()->create();
            foreach ($permissions as $slug) {
                $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
            }
            $user->roles()->attach($role);
        }

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    private function customer(): array
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('portal', ['customer'], now()->addMinutes(120))->plainTextToken;

        return [$customer, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_me_returns_pt_br_locale_by_default_for_staff_and_customer(): void
    {
        [, $h] = $this->staff();
        $this->getJson('/api/me', $h)->assertOk()->assertJsonPath('locale', 'pt_BR');

        [, $h] = $this->customer();
        $this->getJson('/api/customer/me', $h)->assertOk()->assertJsonPath('locale', 'pt_BR');
    }

    public function test_staff_can_update_own_locale_without_audit(): void
    {
        [$user, $h] = $this->staff();

        $this->patchJson('/api/me/locale', ['locale' => 'en'], $h)->assertNoContent();

        $this->assertSame('en', $user->fresh()->locale);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_customer_can_update_own_locale_without_audit(): void
    {
        [$customer, $h] = $this->customer();

        $this->patchJson('/api/customer/me/locale', ['locale' => 'en'], $h)->assertNoContent();

        $this->assertSame('en', $customer->fresh()->locale);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_invalid_locale_is_rejected(): void
    {
        [$user, $h] = $this->staff();
        $this->patchJson('/api/me/locale', ['locale' => 'fr'], $h)->assertStatus(422)->assertJsonValidationErrors('locale');
        $this->patchJson('/api/me/locale', [], $h)->assertStatus(422);
        $this->assertSame('pt_BR', $user->fresh()->locale);

        [, $h] = $this->customer();
        $this->patchJson('/api/customer/me/locale', ['locale' => 'fr'], $h)->assertStatus(422);
    }

    public function test_locale_update_requires_authentication(): void
    {
        $this->patchJson('/api/me/locale', ['locale' => 'en'])->assertStatus(401);
        $this->patchJson('/api/customer/me/locale', ['locale' => 'en'])->assertStatus(401);
    }

    public function test_admin_manages_user_locale(): void
    {
        [, $h] = $this->staff('users.manage');
        $grupo = GrupoSolucao::factory()->create();
        $base = ['name' => 'Ana', 'email' => 'ana@example.com', 'grupo_solucao_id' => $grupo->id];

        $id = $this->postJson('/api/users', $base + ['locale' => 'en'], $h)
            ->assertCreated()->assertJsonPath('data.locale', 'en')->json('data.id');
        $this->assertSame('en', User::find($id)->locale);

        $this->postJson('/api/users', ['email' => 'b@example.com', 'name' => 'B'] + $base, $h)
            ->assertCreated()->assertJsonPath('data.locale', 'pt_BR');

        $this->postJson('/api/users', ['email' => 'c@example.com', 'locale' => 'fr'] + $base, $h)->assertStatus(422);

        $this->putJson("/api/users/{$id}", $base + ['locale' => 'pt_BR'], $h)
            ->assertOk()->assertJsonPath('data.locale', 'pt_BR');
        $this->assertSame('pt_BR', User::find($id)->locale);
    }

    public function test_admin_manages_customer_locale(): void
    {
        [, $h] = $this->staff('customers.manage');
        $client = Client::factory()->create();
        $base = ['name' => 'Cli', 'email' => 'cli@example.com', 'client_id' => $client->id];

        $id = $this->postJson('/api/customers', $base + ['locale' => 'en'], $h)
            ->assertCreated()->assertJsonPath('data.locale', 'en')->json('data.id');

        $this->postJson('/api/customers', ['email' => 'd@example.com'] + $base, $h)
            ->assertCreated()->assertJsonPath('data.locale', 'pt_BR');

        $this->putJson("/api/customers/{$id}", $base + ['locale' => 'pt_BR'], $h)
            ->assertOk()->assertJsonPath('data.locale', 'pt_BR');
        $this->assertSame('pt_BR', Customer::find($id)->locale);
    }

    public function test_preferred_locale_returns_saved_value(): void
    {
        $this->assertSame('pt_BR', User::factory()->create()->fresh()->preferredLocale());
        $this->assertSame('en', User::factory()->create(['locale' => 'en'])->preferredLocale());
        $this->assertSame('en', Customer::factory()->create(['locale' => 'en'])->preferredLocale());
        $this->assertSame('pt_BR', Customer::factory()->create()->fresh()->preferredLocale());
    }
}
