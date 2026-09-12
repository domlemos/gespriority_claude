# Autorização e visibilidade por grupo de solução — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a um admin controle, por `grupo_solucao`, sobre (a) exceções de permission (liberar extra / bloquear) e (b) visibilidade de incidentes por usuário — hoje ambos são só por `Role`, sem exceção nenhuma.

**Architecture:** Duas tabelas pivot novas guardam as exceções (`grupo_solucao_permissoes` com uma coluna `tipo`, e `user_grupo_solucao_visibilidade` sem coluna extra). `User::hasPermission()` passa a considerar as exceções do grupo do usuário, com bypass total pro papel `admin`. Um `Builder` scope (`Incidente::scopeVisiveisPara()`) filtra listagens/agregações por grupo visível, e uma `IncidentePolicy` faz a mesma checagem por instância em `show()`/`update()` e nos recursos aninhados. `IncidenteEvento` ganha um scope equivalente via `whereHas('incidente', ...)` para as agregações de relatório baseadas em evento.

**Tech Stack:** Laravel 13 (Octane/FrankenPHP), PHPUnit (`RefreshDatabase`), Sanctum (tokens de teste via `createToken()`).

**Spec:** `docs/superpowers/specs/2026-09-12-autorizacao-visibilidade-grupo-solucao-design.md`

## Global Constraints

- Grupo sem nenhuma linha nas tabelas novas = comportamento idêntico ao atual (sem regressão silenciosa) — testado explicitamente em cada task.
- Papel `admin` (role com `slug === 'admin'`) tem bypass total nos dois eixos (permissions e visibilidade) — nunca é afetado por bloqueio de grupo nem por escopo de visibilidade.
- Incidente com `grupo_solucao_id === null` é sempre visível a todo staff com `tickets.view`, independente do grupo do usuário (fila de triagem pendente).
- Nenhuma permission nova é criada — os dois endpoints de administração reaproveitam `grupos_solucao.manage` e `users.manage`, já existentes.
- Todo código/comentário novo segue o padrão do projeto: nomes de domínio em português (`grupo_solucao`, `liberada`, `bloqueada`), comentários só quando explicam um "porquê" não óbvio (ver estilo em `app/Models/Incidente.php`, `app/Http/Controllers/Api/IncidenteController.php`).
- Migrations de pivot sem coluna extra usam PK composta, sem `id`/timestamps (mesmo padrão de `permission_role`/`role_user`, ver `database/migrations/2026_07_09_195033_create_permission_role_table.php`).

---

### Task 1: Migration + relações de `grupo_solucao_permissoes`

**Files:**
- Create: `database/migrations/2026_09_12_100000_create_grupo_solucao_permissoes_table.php`
- Modify: `app/Models/GrupoSolucao.php:1-51`
- Test: `tests/Feature/GruposSolucao/GrupoSolucaoPermissaoOverrideRelationsTest.php`

**Interfaces:**
- Produces: `GrupoSolucao::permissoesLiberadas(): BelongsToMany` (pivot `tipo = 'liberada'`), `GrupoSolucao::permissoesBloqueadas(): BelongsToMany` (pivot `tipo = 'bloqueada'`). Usadas pela Task 3 (`User::hasPermission()`) e Task 9 (endpoint de administração).

- [ ] **Step 1: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupo_solucao_permissoes', function (Blueprint $table) {
            $table->foreignId('grupo_solucao_id')->constrained('grupos_solucao')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            // 'liberada' (o grupo concede essa permission além do que o Role
            // já dá) ou 'bloqueada' (o grupo retira essa permission mesmo
            // que o Role conceda) — ver User::hasPermission().
            $table->string('tipo');
            $table->primary(['grupo_solucao_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_solucao_permissoes');
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `grupo_solucao_permissoes` criada sem erro (no ambiente Docker do projeto: `docker compose exec app php artisan migrate`).

- [ ] **Step 3: Escrever o teste das relações (falhando)**

```php
<?php

namespace Tests\Feature\GruposSolucao;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrupoSolucaoPermissaoOverrideRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissoes_liberadas_returns_only_pivot_rows_marked_as_liberada(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $liberada = Permission::factory()->create(['slug' => 'tickets.assign']);
        $bloqueada = Permission::factory()->create(['slug' => 'tickets.manage']);

        $grupo->permissoesLiberadas()->attach($liberada->id, ['tipo' => 'liberada']);
        $grupo->permissoesBloqueadas()->attach($bloqueada->id, ['tipo' => 'bloqueada']);

        $this->assertTrue($grupo->permissoesLiberadas->contains('id', $liberada->id));
        $this->assertFalse($grupo->permissoesLiberadas->contains('id', $bloqueada->id));
    }

    public function test_permissoes_bloqueadas_returns_only_pivot_rows_marked_as_bloqueada(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $liberada = Permission::factory()->create(['slug' => 'tickets.assign']);
        $bloqueada = Permission::factory()->create(['slug' => 'tickets.manage']);

        $grupo->permissoesLiberadas()->attach($liberada->id, ['tipo' => 'liberada']);
        $grupo->permissoesBloqueadas()->attach($bloqueada->id, ['tipo' => 'bloqueada']);

        $this->assertTrue($grupo->permissoesBloqueadas->contains('id', $bloqueada->id));
        $this->assertFalse($grupo->permissoesBloqueadas->contains('id', $liberada->id));
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=GrupoSolucaoPermissaoOverrideRelationsTest`
Expected: FAIL — `Call to undefined method App\Models\GrupoSolucao::permissoesLiberadas()`.

- [ ] **Step 5: Adicionar as relações em `GrupoSolucao`**

Em `app/Models/GrupoSolucao.php`, adicionar o import (junto aos demais, linha 6) e os dois métodos logo depois de `incidentes()` (após a linha 34, antes do bloco de comentário do `scopeFiltros`):

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
```

```php
    public function permissoesLiberadas(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'grupo_solucao_permissoes')
            ->wherePivot('tipo', 'liberada');
    }

    public function permissoesBloqueadas(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'grupo_solucao_permissoes')
            ->wherePivot('tipo', 'bloqueada');
    }
```

(`Permission` já precisa ser importado: adicionar `use App\Models\Permission;` junto aos demais imports do arquivo.)

- [ ] **Step 6: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=GrupoSolucaoPermissaoOverrideRelationsTest`
Expected: PASS (2 testes).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_12_100000_create_grupo_solucao_permissoes_table.php app/Models/GrupoSolucao.php tests/Feature/GruposSolucao/GrupoSolucaoPermissaoOverrideRelationsTest.php
git commit -m "feat: add grupo_solucao_permissoes table and relations"
```

---

### Task 2: Migration + relação de `user_grupo_solucao_visibilidade`

**Files:**
- Create: `database/migrations/2026_09_12_100001_create_user_grupo_solucao_visibilidade_table.php`
- Modify: `app/Models/User.php:1-95`
- Test: `tests/Feature/Users/UserGruposVisiveisExtraRelationTest.php`

**Interfaces:**
- Produces: `User::gruposVisiveisExtra(): BelongsToMany` (grupos extras além do `grupo_solucao_id` próprio). Usada pela Task 4 (`User::visibleGrupoSolucaoIds()`) e Task 10 (endpoint de administração).

- [ ] **Step 1: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_grupo_solucao_visibilidade', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grupo_solucao_id')->constrained('grupos_solucao')->cascadeOnDelete();
            $table->primary(['user_id', 'grupo_solucao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_grupo_solucao_visibilidade');
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `user_grupo_solucao_visibilidade` criada sem erro.

- [ ] **Step 3: Escrever o teste da relação (falhando)**

```php
<?php

namespace Tests\Feature\Users;

use App\Models\GrupoSolucao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserGruposVisiveisExtraRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gruposVisiveisExtra_returns_attached_grupos(): void
    {
        $user = User::factory()->create();
        $grupoExtra = GrupoSolucao::factory()->create();

        $user->gruposVisiveisExtra()->attach($grupoExtra->id);

        $this->assertTrue($user->gruposVisiveisExtra->contains('id', $grupoExtra->id));
    }

    public function test_gruposVisiveisExtra_does_not_include_the_users_own_grupo_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->gruposVisiveisExtra);
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=UserGruposVisiveisExtraRelationTest`
Expected: FAIL — `Call to undefined method App\Models\User::gruposVisiveisExtra()`.

- [ ] **Step 5: Adicionar a relação em `User`**

Em `app/Models/User.php`, adicionar logo após `grupoSolucao()` (depois da linha 53, antes de `incidentesResponsavel()`):

```php
    public function gruposVisiveisExtra(): BelongsToMany
    {
        return $this->belongsToMany(GrupoSolucao::class, 'user_grupo_solucao_visibilidade');
    }
```

(`BelongsToMany` já está importado no arquivo; `GrupoSolucao` também já está importado.)

- [ ] **Step 6: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=UserGruposVisiveisExtraRelationTest`
Expected: PASS (2 testes).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_12_100001_create_user_grupo_solucao_visibilidade_table.php app/Models/User.php tests/Feature/Users/UserGruposVisiveisExtraRelationTest.php
git commit -m "feat: add user_grupo_solucao_visibilidade table and relation"
```

---

### Task 3: `User::isAdmin()` e `hasPermission()` considerando exceções de grupo

**Files:**
- Modify: `app/Models/User.php:65-74`
- Test: `tests/Feature/Authorization/GrupoSolucaoPermissionOverrideTest.php`

**Interfaces:**
- Consumes: `GrupoSolucao::permissoesLiberadas`/`permissoesBloqueadas` (Task 1), `User::roles` (existente).
- Produces: `User::isAdmin(): bool`. Usada pelas Tasks 4, 6 (Policy) e pelos testes de Dashboard/Relatório (Task 8) para montar um usuário com bypass total.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrupoSolucaoPermissionOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function userComRole(array $permissionSlugs, ?string $roleSlug = null): User
    {
        $role = Role::factory()->create($roleSlug ? ['slug' => $roleSlug] : []);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_role_grants_permission_when_grupo_has_no_override(): void
    {
        $user = $this->userComRole(['tickets.manage']);

        $this->assertTrue($user->hasPermission('tickets.manage'));
    }

    public function test_role_does_not_grant_permission_it_never_had_and_grupo_has_no_override(): void
    {
        $user = $this->userComRole(['tickets.view']);

        $this->assertFalse($user->hasPermission('tickets.manage'));
    }

    public function test_grupo_liberada_grants_a_permission_the_role_does_not_have(): void
    {
        $user = $this->userComRole(['tickets.view']);
        $extra = Permission::factory()->create(['slug' => 'tickets.assign']);
        $user->grupoSolucao->permissoesLiberadas()->attach($extra->id, ['tipo' => 'liberada']);

        $this->assertTrue($user->hasPermission('tickets.assign'));
    }

    public function test_grupo_bloqueada_revokes_a_permission_the_role_grants(): void
    {
        $user = $this->userComRole(['tickets.manage']);
        $permission = Permission::where('slug', 'tickets.manage')->sole();
        $user->grupoSolucao->permissoesBloqueadas()->attach($permission->id, ['tipo' => 'bloqueada']);

        $this->assertFalse($user->hasPermission('tickets.manage'));
    }

    public function test_admin_bypasses_grupo_bloqueada(): void
    {
        $admin = $this->userComRole(['tickets.manage'], 'admin');
        $permission = Permission::where('slug', 'tickets.manage')->sole();
        $admin->grupoSolucao->permissoesBloqueadas()->attach($permission->id, ['tipo' => 'bloqueada']);

        $this->assertTrue($admin->hasPermission('tickets.manage'));
    }

    public function test_admin_does_not_gain_permission_via_grupo_liberada(): void
    {
        // Bypass do admin corta os dois lados: grupo nunca acrescenta nem
        // tira permission de um admin, só o Role importa.
        $admin = $this->userComRole(['tickets.view'], 'admin');
        $extra = Permission::factory()->create(['slug' => 'tickets.assign']);
        $admin->grupoSolucao->permissoesLiberadas()->attach($extra->id, ['tipo' => 'liberada']);

        $this->assertFalse($admin->hasPermission('tickets.assign'));
    }

    public function test_isAdmin_is_true_only_for_a_role_with_the_admin_slug(): void
    {
        $admin = $this->userComRole([], 'admin');
        $agente = $this->userComRole([], 'agente');

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($agente->isAdmin());
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=GrupoSolucaoPermissionOverrideTest`
Expected: FAIL — `Call to undefined method App\Models\User::isAdmin()` (e as asserções de bloqueio/liberação ainda não implementadas).

- [ ] **Step 3: Implementar `isAdmin()` e atualizar `hasPermission()`**

Substituir em `app/Models/User.php:65-74`:

```php
    public function hasPermission(string $slug): bool
    {
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->pluck('permissions')
            ->flatten()
            ->pluck('slug')
            ->contains($slug);
    }
```

por:

```php
    public function isAdmin(): bool
    {
        return $this->loadMissing('roles')->roles->contains('slug', 'admin');
    }

    public function hasPermission(string $slug): bool
    {
        $this->loadMissing('roles.permissions');

        $viaRole = $this->roles
            ->pluck('permissions')
            ->flatten()
            ->pluck('slug')
            ->contains($slug);

        // Admin nunca é afetado por exceção de grupo — nem pra ganhar
        // (permissoesLiberadas) nem pra perder (permissoesBloqueadas) uma
        // permission. Evita lockout do sistema por má configuração de
        // grupo (ver BACKEND_SPECS.md e design spec desta feature).
        if ($this->isAdmin()) {
            return $viaRole;
        }

        $this->loadMissing('grupoSolucao.permissoesLiberadas', 'grupoSolucao.permissoesBloqueadas');

        if ($this->grupoSolucao->permissoesBloqueadas->pluck('slug')->contains($slug)) {
            return false;
        }

        return $viaRole || $this->grupoSolucao->permissoesLiberadas->pluck('slug')->contains($slug);
    }
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=GrupoSolucaoPermissionOverrideTest`
Expected: PASS (7 testes).

- [ ] **Step 5: Rodar a suíte completa pra checar regressão**

Run: `php artisan test`
Expected: PASS — `hasPermission()` é usado pelo `Gate::before()` em toda rota `can:*`; nenhum teste existente configura `permissoesLiberadas`/`permissoesBloqueadas`, então o comportamento por Role sozinho não muda pra nenhum teste hoje.

- [ ] **Step 6: Commit**

```bash
git add app/Models/User.php tests/Feature/Authorization/GrupoSolucaoPermissionOverrideTest.php
git commit -m "feat: apply grupo_solucao permission overrides in User::hasPermission with admin bypass"
```

---

### Task 4: `User::visibleGrupoSolucaoIds()`

**Files:**
- Modify: `app/Models/User.php` (novo método, após `gruposVisiveisExtra()` da Task 2)
- Test: `tests/Feature/Authorization/VisibleGrupoSolucaoIdsTest.php`

**Interfaces:**
- Consumes: `User::isAdmin()` (Task 3), `User::gruposVisiveisExtra` (Task 2), `User::grupo_solucao_id` (coluna existente).
- Produces: `User::visibleGrupoSolucaoIds(): ?array` — `null` significa "sem restrição" (admin); array de ids caso contrário. Usada por `Incidente::scopeVisiveisPara()` (Task 5) e `IncidentePolicy` (Task 6).

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisibleGrupoSolucaoIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_for_an_admin(): void
    {
        $role = Role::factory()->create(['slug' => 'admin']);
        $user = User::factory()->create();
        $user->roles()->attach($role);

        $this->assertNull($user->visibleGrupoSolucaoIds());
    }

    public function test_returns_only_the_own_grupo_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertSame([$user->grupo_solucao_id], $user->visibleGrupoSolucaoIds());
    }

    public function test_includes_extra_granted_grupos(): void
    {
        $user = User::factory()->create();
        $extra = GrupoSolucao::factory()->create();
        $user->gruposVisiveisExtra()->attach($extra->id);

        $ids = $user->visibleGrupoSolucaoIds();

        $this->assertEqualsCanonicalizing([$user->grupo_solucao_id, $extra->id], $ids);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=VisibleGrupoSolucaoIdsTest`
Expected: FAIL — `Call to undefined method App\Models\User::visibleGrupoSolucaoIds()`.

- [ ] **Step 3: Implementar**

Adicionar em `app/Models/User.php`, logo após `gruposVisiveisExtra()`:

```php
    /**
     * `null` = sem restrição (bypass do admin, ver isAdmin()). Caso
     * contrário, o próprio grupo do usuário mais os grupos extras
     * concedidos individualmente (gruposVisiveisExtra) — nunca por Role
     * inteiro, ver design spec desta feature.
     */
    public function visibleGrupoSolucaoIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        $this->loadMissing('gruposVisiveisExtra');

        return [$this->grupo_solucao_id, ...$this->gruposVisiveisExtra->pluck('id')->all()];
    }
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=VisibleGrupoSolucaoIdsTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/Authorization/VisibleGrupoSolucaoIdsTest.php
git commit -m "feat: add User::visibleGrupoSolucaoIds for incidente visibility scoping"
```

---

### Task 5: `Incidente::scopeVisiveisPara()` aplicado em `IncidenteController::index()`

**Files:**
- Modify: `app/Models/Incidente.php:136` (novo scope logo após `scopeFiltrosRelatorio`, antes da linha 161)
- Modify: `app/Http/Controllers/Api/IncidenteController.php:31-50`
- Modify: `tests/Feature/Incidentes/IncidenteCrudTest.php` (helper `staffToken()` e 2 testes que quebram)
- Test: `tests/Feature/Authorization/IncidenteVisibilidadeListagemTest.php`

**Interfaces:**
- Consumes: `User::visibleGrupoSolucaoIds()` (Task 4).
- Produces: `Incidente::scopeVisiveisPara(Builder $query, User $user): Builder` (chamável como `Incidente::query()->visiveisPara($user)`). Reaproveitada por `IncidentePolicy` (Task 6, indiretamente) e por `IncidenteEvento::scopeVisiveisPara()` (Task 8).

- [ ] **Step 1: Escrever o teste de listagem (falhando)**

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteVisibilidadeListagemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null, bool $asAdmin = false): array
    {
        $role = Role::factory()->create($asAdmin ? ['slug' => 'admin'] : []);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_user_does_not_see_incidentes_from_a_grupo_it_has_no_access_to(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $outroGrupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $outroGrupo->id]);
        [$token] = $this->staffToken(['tickets.view'], $meuGrupo);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_sees_incidentes_from_its_own_grupo(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $meuGrupo->id]);
        [$token] = $this->staffToken(['tickets.view'], $meuGrupo);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_user_sees_incidentes_with_no_grupo_regardless_of_own_grupo(): void
    {
        Incidente::factory()->create(['grupo_solucao_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_user_sees_incidentes_from_an_extra_granted_grupo(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $extra = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $extra->id]);
        [$token, $user] = $this->staffToken(['tickets.view'], $meuGrupo);
        $user->gruposVisiveisExtra()->attach($extra->id);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_sees_incidentes_from_every_grupo(): void
    {
        Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view'], null, true);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=IncidenteVisibilidadeListagemTest`
Expected: FAIL em `test_user_does_not_see_incidentes_from_a_grupo_it_has_no_access_to` (espera 0, recebe 1 — hoje não há filtro nenhum).

- [ ] **Step 3: Implementar o scope em `Incidente`**

Adicionar em `app/Models/Incidente.php`, logo após o fim de `scopeFiltrosRelatorio()` (depois da linha 158, antes da linha 160 `/** Colunas aceitas em \`sort_by\`... */`):

```php
    /**
     * Restringe a incidentes que o usuário pode enxergar: o próprio grupo
     * de solução, os grupos extras concedidos individualmente
     * (User::gruposVisiveisExtra), e qualquer incidente ainda sem grupo
     * (fila de triagem pendente — visível a todos, senão ninguém
     * conseguiria rotear um chamado recém-criado). `admin` não tem
     * restrição nenhuma (User::visibleGrupoSolucaoIds() retorna `null`).
     */
    public function scopeVisiveisPara(Builder $query, User $user): Builder
    {
        $ids = $user->visibleGrupoSolucaoIds();

        if ($ids === null) {
            return $query;
        }

        return $query->where(
            fn (Builder $q) => $q->whereNull('grupo_solucao_id')->orWhereIn('grupo_solucao_id', $ids)
        );
    }
```

(`User` está no mesmo namespace `App\Models`, não precisa de `use` novo.)

- [ ] **Step 4: Aplicar o scope em `IncidenteController::index()`**

Em `app/Http/Controllers/Api/IncidenteController.php:43-49`, mudar:

```php
        return IncidenteResource::collection(
            Incidente::query()
                ->filtros($filtros)
                ->with(self::RELATIONS)
                ->latest()
                ->paginate($request->integer('per_page', 15))
        );
```

para:

```php
        return IncidenteResource::collection(
            Incidente::query()
                ->visiveisPara($request->user())
                ->filtros($filtros)
                ->with(self::RELATIONS)
                ->latest()
                ->paginate($request->integer('per_page', 15))
        );
```

- [ ] **Step 5: Rodar o teste novo e confirmar que passa**

Run: `php artisan test --filter=IncidenteVisibilidadeListagemTest`
Expected: PASS (5 testes).

- [ ] **Step 6: Rodar a suíte completa e identificar as quebras esperadas**

Run: `php artisan test --filter=IncidenteCrudTest`
Expected: FAIL em exatamente 2 testes — `test_can_filter_incidentes_by_grupo_solucao_id` e `test_can_combine_multiple_filters` — porque o usuário de teste (`staffToken()`) tem um `grupo_solucao_id` aleatório, diferente do `$grupo` usado nos incidentes desses dois testes.

- [ ] **Step 7: Corrigir o helper `staffToken()` de `IncidenteCrudTest` e os 2 testes**

Em `tests/Feature/Incidentes/IncidenteCrudTest.php`, adicionar o import `use App\Models\GrupoSolucao;` (já está importado, ver linha 7 do arquivo) e trocar a assinatura do helper (linhas 28-42):

```php
    /**
     * @param  string[]  $permissionSlugs
     * @return array{0: string, 1: User}
     */
    private function staffToken(array $permissionSlugs): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }
```

por:

```php
    /**
     * @param  string[]  $permissionSlugs
     * @return array{0: string, 1: User}
     */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }
```

Depois, em `test_can_filter_incidentes_by_grupo_solucao_id` (linhas 133-143), trocar `[$token] = $this->staffToken(['tickets.view']);` por `[$token] = $this->staffToken(['tickets.view'], $grupo);` (a variável `$grupo` já existe no teste, criada na linha 135).

Em `test_can_combine_multiple_filters` (linhas 157-171), trocar `[$token] = $this->staffToken(['tickets.view']);` por `[$token] = $this->staffToken(['tickets.view'], $grupo);` (a variável `$grupo` já existe, criada na linha 159).

- [ ] **Step 8: Rodar `IncidenteCrudTest` de novo e confirmar que passa**

Run: `php artisan test --filter=IncidenteCrudTest`
Expected: PASS — todos os testes deste arquivo, incluindo os 2 corrigidos. (Os outros 2 testes que também vão quebrar neste arquivo — `test_staff_with_view_permission_can_view_a_single_incidente_with_relations_loaded` e `test_updating_to_the_same_grupo_solucao_id_does_not_create_an_escalonamento_entry` — dependem de `show()`/`update()`, ainda não tocados nesta task; ficam para a Task 6.)

- [ ] **Step 9: Commit**

```bash
git add app/Models/Incidente.php app/Http/Controllers/Api/IncidenteController.php tests/Feature/Incidentes/IncidenteCrudTest.php tests/Feature/Authorization/IncidenteVisibilidadeListagemTest.php
git commit -m "feat: scope incidente listing by visible grupo_solucao"
```

---

### Task 6: `IncidentePolicy` aplicada em `show()`/`update()`

**Files:**
- Modify: `app/Http/Controllers/Controller.php`
- Create: `app/Policies/IncidentePolicy.php`
- Modify: `app/Http/Controllers/Api/IncidenteController.php:100-119`
- Modify: `tests/Feature/Incidentes/IncidenteCrudTest.php` (2 testes que quebram)
- Test: `tests/Feature/Authorization/IncidenteVisibilidadeDetalheTest.php`

**Interfaces:**
- Consumes: `User::visibleGrupoSolucaoIds()` (Task 4).
- Produces: `IncidentePolicy::view(User $user, Incidente $incidente): bool`, `IncidentePolicy::update(...)`. Descoberta automática pelo Laravel (convenção `Model` → `{Model}Policy`, sem registro manual necessário no Laravel 13). Reaproveitada pela Task 7.

- [ ] **Step 1: Escrever o teste de detalhe/atualização (falhando)**

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteVisibilidadeDetalheTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null, bool $asAdmin = false): array
    {
        $role = Role::factory()->create($asAdmin ? ['slug' => 'admin'] : []);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_cannot_view_a_single_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_can_view_a_single_incidente_with_no_grupo(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token))->assertOk();
    }

    public function test_admin_can_view_a_single_incidente_from_any_grupo(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view'], null, true);

        $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token))->assertOk();
    }

    public function test_cannot_update_an_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", ['titulo' => 'Novo'], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_can_update_an_incidente_within_the_visible_scope(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $meuGrupo->id]);
        [$token] = $this->staffToken(['tickets.manage'], $meuGrupo);

        $this->putJson("/api/incidentes/{$incidente->id}", ['titulo' => 'Novo'], $this->authHeader($token))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=IncidenteVisibilidadeDetalheTest`
Expected: FAIL em `test_cannot_view_a_single_incidente_outside_the_visible_scope` e `test_cannot_update_an_incidente_outside_the_visible_scope` (esperam 403, hoje `show()`/`update()` não checam nada além da permission de rota).

- [ ] **Step 3: Habilitar `$this->authorize()` no `Controller` base**

Em `app/Http/Controllers/Controller.php`, trocar:

```php
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
```

por:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
}
```

- [ ] **Step 4: Criar a `IncidentePolicy`**

```php
<?php

namespace App\Policies;

use App\Models\Incidente;
use App\Models\User;

class IncidentePolicy
{
    /**
     * `admin` não tem restrição (visibleGrupoSolucaoIds() === null); um
     * incidente sem grupo (triagem pendente) é visível a qualquer staff
     * com a permission de rota — a mesma regra de Incidente::scopeVisiveisPara().
     */
    public function view(User $user, Incidente $incidente): bool
    {
        $ids = $user->visibleGrupoSolucaoIds();

        return $ids === null
            || $incidente->grupo_solucao_id === null
            || in_array($incidente->grupo_solucao_id, $ids, true);
    }

    public function update(User $user, Incidente $incidente): bool
    {
        return $this->view($user, $incidente);
    }
}
```

- [ ] **Step 5: Aplicar a policy em `show()`/`update()`**

Em `app/Http/Controllers/Api/IncidenteController.php:100-103`, mudar:

```php
    public function show(Incidente $incidente)
    {
        return new IncidenteResource($incidente->load(self::RELATIONS));
    }
```

para:

```php
    public function show(Incidente $incidente)
    {
        $this->authorize('view', $incidente);

        return new IncidenteResource($incidente->load(self::RELATIONS));
    }
```

E em `app/Http/Controllers/Api/IncidenteController.php:105-119`, adicionar a checagem como primeira linha do método (antes de `$data = $request->validate([...`):

```php
    public function update(Request $request, Incidente $incidente)
    {
        $this->authorize('update', $incidente);

        // Update parcial de propósito (...) — resto do método inalterado.
        $data = $request->validate([
```

- [ ] **Step 6: Rodar o teste novo e confirmar que passa**

Run: `php artisan test --filter=IncidenteVisibilidadeDetalheTest`
Expected: PASS (5 testes).

- [ ] **Step 7: Corrigir os 2 testes de `IncidenteCrudTest` que quebram**

Em `tests/Feature/Incidentes/IncidenteCrudTest.php`, `test_staff_with_view_permission_can_view_a_single_incidente_with_relations_loaded` (linhas 230-249): trocar `[$token] = $this->staffToken(['tickets.view']);` (linha 240) por `[$token] = $this->staffToken(['tickets.view'], $grupo);` (a variável `$grupo` já existe, criada na linha 234).

Em `test_updating_to_the_same_grupo_solucao_id_does_not_create_an_escalonamento_entry` (linhas 583-596): trocar `[$token] = $this->staffToken(['tickets.manage']);` (linha 587) por `[$token] = $this->staffToken(['tickets.manage'], $grupo);` (a variável `$grupo` já existe, criada na linha 585).

- [ ] **Step 8: Rodar a suíte de Incidentes completa**

Run: `php artisan test --filter=IncidenteCrudTest`
Expected: PASS — todos os testes.

Run: `php artisan test tests/Feature/Incidentes`
Expected: PASS — `IncidenteSlaTest`, `IncidenteDescricaoCrudTest` e `IncidenteAnexoCrudTest` não criam incidentes com `grupo_solucao_id` explícito (default `null` da factory), então não são afetados por esta task.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Controller.php app/Policies/IncidentePolicy.php app/Http/Controllers/Api/IncidenteController.php tests/Feature/Incidentes/IncidenteCrudTest.php tests/Feature/Authorization/IncidenteVisibilidadeDetalheTest.php
git commit -m "feat: add IncidentePolicy to scope show/update by visible grupo_solucao"
```

---

### Task 7: Aplicar a policy nos recursos aninhados (`descricoes`, `anexos`)

**Files:**
- Modify: `app/Http/Controllers/Api/IncidenteDescricaoController.php:13-71`
- Modify: `app/Http/Controllers/Api/AnexoController.php:41-105`
- Test: `tests/Feature/Authorization/IncidenteRecursosAninhadosVisibilidadeTest.php`

**Interfaces:**
- Consumes: `IncidentePolicy::view()` (Task 6).

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\Anexo;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteRecursosAninhadosVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_cannot_list_descricoes_of_an_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_cannot_list_anexos_of_an_incidente_outside_the_visible_scope(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        Anexo::factory()->create(['incidente_id' => $incidente->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}/anexos", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_can_list_descricoes_of_an_incidente_with_no_grupo(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token))->assertOk();
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=IncidenteRecursosAninhadosVisibilidadeTest`
Expected: FAIL nos 2 primeiros testes (esperam 403, hoje respondem 200).

- [ ] **Step 3: Adicionar a checagem em `IncidenteDescricaoController`**

Em `app/Http/Controllers/Api/IncidenteDescricaoController.php`, adicionar `$this->authorize('view', $incidente);` como primeira linha de cada método que recebe `Incidente $incidente`:

- `index(Request $request, Incidente $incidente)` (linha 13): primeira linha do corpo.
- `store(Request $request, Incidente $incidente)` (linha 22): primeira linha do corpo.
- `show(Incidente $incidente, IncidenteDescricao $descricao)` (linha 43): primeira linha do corpo, antes de `$this->ensureBelongsTo(...)`.
- `update(Request $request, Incidente $incidente, IncidenteDescricao $descricao)` (linha 50): primeira linha do corpo, antes de `$this->ensureBelongsTo(...)`.
- `destroy(Request $request, Incidente $incidente, IncidenteDescricao $descricao)` (linha 64): primeira linha do corpo, antes de `$this->ensureBelongsTo(...)`.

Exemplo (`index`):

```php
    public function index(Request $request, Incidente $incidente)
    {
        $this->authorize('view', $incidente);

        return IncidenteDescricaoResource::collection(
            $incidente->descricoes()
                ->with('user')
                ->paginate($request->integer('per_page', 15))
        );
    }
```

- [ ] **Step 4: Adicionar a checagem em `AnexoController`**

Em `app/Http/Controllers/Api/AnexoController.php`, adicionar `$this->authorize('view', $incidente);` como primeira linha de:

- `index(Request $request, Incidente $incidente)` (linha 41).
- `store(Request $request, Incidente $incidente)` (linha 50).
- `download(Incidente $incidente, Anexo $anexo)` (linha 92): primeira linha, antes de `$this->ensureBelongsTo(...)`.
- `destroy(Incidente $incidente, Anexo $anexo)` (linha 99): primeira linha, antes de `$this->ensureBelongsTo(...)`.

- [ ] **Step 5: Rodar o teste novo e confirmar que passa**

Run: `php artisan test --filter=IncidenteRecursosAninhadosVisibilidadeTest`
Expected: PASS (3 testes).

- [ ] **Step 6: Rodar as suítes existentes de descricoes/anexos**

Run: `php artisan test tests/Feature/Incidentes/IncidenteDescricaoCrudTest.php tests/Feature/Incidentes/IncidenteAnexoCrudTest.php`
Expected: PASS — nenhum desses testes cria incidente com `grupo_solucao_id` explícito, então a checagem nova sempre passa pra eles (regra do grupo nulo).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/IncidenteDescricaoController.php app/Http/Controllers/Api/AnexoController.php tests/Feature/Authorization/IncidenteRecursosAninhadosVisibilidadeTest.php
git commit -m "feat: enforce incidente visibility on nested descricoes/anexos routes"
```

---

### Task 8: Escopo de visibilidade em Dashboard e Relatórios

**Files:**
- Modify: `app/Models/IncidenteEvento.php:74` (novo scope antes da linha 74, ou logo após — ver Step 1)
- Modify: `app/Http/Controllers/Api/DashboardController.php:14-42`
- Modify: `app/Http/Controllers/Api/RelatorioController.php:23-185`
- Modify: `app/Http/Controllers/Api/RelatorioSalvoController.php:58-66`
- Modify: `tests/Feature/Dashboard/IncidentesDashboardTest.php` (helper `staffToken()` e 2 testes)
- Modify: `tests/Feature/Relatorios/RelatorioIncidentesTest.php` (helper `staffToken()` e 2 testes)
- Test: `tests/Feature/Authorization/DashboardRelatorioVisibilidadeTest.php`

**Interfaces:**
- Consumes: `Incidente::scopeVisiveisPara()` (Task 5).
- Produces: `IncidenteEvento::scopeVisiveisPara(Builder $query, User $user): Builder`.
- Modifica assinaturas: `RelatorioController::responder(array $filtros, string $agruparPor, string $formato, User $user)` e `RelatorioController::agregar(array $filtros, string $agruparPor, User $user): Collection` — os únicos dois call sites são `RelatorioController::index()` e `RelatorioSalvoController::executar()`, ambos ajustados nesta task.

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRelatorioVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_dashboard_does_not_list_incidentes_from_an_invisible_grupo(): void
    {
        Incidente::factory()->create(['grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/dashboard/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_relatorio_grupo_solucao_only_counts_visible_incidentes(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create(['nome' => 'Meu Grupo']);
        $outroGrupo = GrupoSolucao::factory()->create(['nome' => 'Outro Grupo']);
        Incidente::factory()->create(['status' => 'fechado', 'grupo_solucao_id' => $meuGrupo->id]);
        Incidente::factory()->create(['status' => 'fechado', 'grupo_solucao_id' => $outroGrupo->id]);
        [$token] = $this->staffToken(['relatorios.view'], $meuGrupo);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=grupo_solucao', $this->authHeader($token));

        $response->assertOk();
        $rotulos = collect($response->json('data'))->pluck('rotulo');
        $this->assertTrue($rotulos->contains('Meu Grupo'));
        $this->assertFalse($rotulos->contains('Outro Grupo'));
    }

    public function test_relatorio_resolvido_por_only_counts_events_from_visible_incidentes(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $outroGrupo = GrupoSolucao::factory()->create();
        $agente = User::factory()->create(['name' => 'Agente Visível']);
        $agenteFora = User::factory()->create(['name' => 'Agente Invisível']);
        $incidenteVisivel = Incidente::factory()->create(['status' => 'resolvido', 'grupo_solucao_id' => $meuGrupo->id]);
        $incidenteInvisivel = Incidente::factory()->create(['status' => 'resolvido', 'grupo_solucao_id' => $outroGrupo->id]);
        \App\Models\IncidenteEvento::factory()->create(['incidente_id' => $incidenteVisivel->id, 'user_id' => $agente->id]);
        \App\Models\IncidenteEvento::factory()->create(['incidente_id' => $incidenteInvisivel->id, 'user_id' => $agenteFora->id]);
        [$token] = $this->staffToken(['relatorios.view'], $meuGrupo);

        $response = $this->getJson('/api/relatorios/incidentes?agrupar_por=resolvido_por', $this->authHeader($token));

        $rotulos = collect($response->json('data'))->pluck('rotulo');
        $this->assertTrue($rotulos->contains('Agente Visível'));
        $this->assertFalse($rotulos->contains('Agente Invisível'));
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=DashboardRelatorioVisibilidadeTest`
Expected: FAIL nos 3 testes (hoje dashboard/relatório não filtram por visibilidade nenhuma).

- [ ] **Step 3: Adicionar `IncidenteEvento::scopeVisiveisPara()`**

Em `app/Models/IncidenteEvento.php`, logo após o fim de `scopeFiltrosRelatorio()` (depois da linha 96, antes do `}` de fechamento da classe na linha 97):

```php

    /** Mesma regra de Incidente::scopeVisiveisPara(), via whereHas('incidente') — o evento em si não tem grupo_solucao_id, quem tem é o incidente relacionado. */
    public function scopeVisiveisPara(Builder $query, User $user): Builder
    {
        return $query->whereHas('incidente', fn (Builder $qi) => $qi->visiveisPara($user));
    }
```

- [ ] **Step 4: Aplicar o scope em `DashboardController`**

Em `app/Http/Controllers/Api/DashboardController.php:35-41`, mudar:

```php
        return IncidenteDashboardResource::collection(
            Incidente::query()
                ->filtros($filtros)
                ->ordenarPor($filtros['sort_by'] ?? null, $filtros['sort_dir'] ?? 'asc')
                ->with(['customer.client', 'item.subcategoria.categoria', 'grupoSolucao', 'responsavel'])
                ->paginate($request->integer('per_page', 15))
        );
```

para:

```php
        return IncidenteDashboardResource::collection(
            Incidente::query()
                ->visiveisPara($request->user())
                ->filtros($filtros)
                ->ordenarPor($filtros['sort_by'] ?? null, $filtros['sort_dir'] ?? 'asc')
                ->with(['customer.client', 'item.subcategoria.categoria', 'grupoSolucao', 'responsavel'])
                ->paginate($request->integer('per_page', 15))
        );
```

- [ ] **Step 5: Passar `User $user` por `RelatorioController`**

Em `app/Http/Controllers/Api/RelatorioController.php:23-28`, mudar:

```php
    public function index(Request $request)
    {
        [$filtros, $agruparPor, $formato] = $this->validado($request);

        return $this->responder($filtros, $agruparPor, $formato);
    }
```

para:

```php
    public function index(Request $request)
    {
        [$filtros, $agruparPor, $formato] = $this->validado($request);

        return $this->responder($filtros, $agruparPor, $formato, $request->user());
    }
```

Em `app/Http/Controllers/Api/RelatorioController.php:61-70`, mudar a assinatura e a chamada interna:

```php
    public function responder(array $filtros, string $agruparPor, string $formato)
    {
        $linhas = $this->agregar($filtros, $agruparPor);
```

para:

```php
    public function responder(array $filtros, string $agruparPor, string $formato, User $user)
    {
        $linhas = $this->agregar($filtros, $agruparPor, $user);
```

Em `app/Http/Controllers/Api/RelatorioController.php:104-184` (método `agregar`), mudar a assinatura e adicionar `->visiveisPara($user)` em toda base de query (`Incidente::query()` e `IncidenteEvento::query()`):

```php
    private function agregar(array $filtros, string $agruparPor, User $user): Collection
    {
        if (isset(self::EVENTOS_POR_USUARIO[$agruparPor])) {
            return $this->comRotulos(
                $this->contarAgrupado(
                    IncidenteEvento::query()->whereIn('tipo', self::EVENTOS_POR_USUARIO[$agruparPor])->visiveisPara($user)->filtrosRelatorio($filtros),
                    'user_id'
                ),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(usuário desconhecido)',
            );
        }

        if ($agruparPor === 'encaminhado_para_grupo') {
            return $this->comRotulos(
                $this->contarAgrupado(
                    IncidenteEvento::query()->where('tipo', IncidenteEvento::TIPO_ENCAMINHADO_GRUPO)->visiveisPara($user)->filtrosRelatorio($filtros),
                    'alvo_id'
                ),
                fn (array $ids) => GrupoSolucao::whereIn('id', $ids)->pluck('nome', 'id'),
                '(sem grupo)',
            );
        }

        if ($agruparPor === 'encaminhado_para_responsavel') {
            return $this->comRotulos(
                $this->contarAgrupado(
                    IncidenteEvento::query()->where('tipo', IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL)->visiveisPara($user)->filtrosRelatorio($filtros),
                    'alvo_id'
                ),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(sem responsável)',
            );
        }

        if ($agruparPor === 'aberto_por') {
            return $this->comRotulos(
                $this->contarAgrupado(Incidente::query()->visiveisPara($user)->filtrosRelatorio($filtros), 'criado_por_id'),
                fn (array $ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
                '(desconhecido)',
            );
        }

        $query = Incidente::query()->visiveisPara($user)->filtrosRelatorio($filtros);
```

(o restante do método, a partir de `if (in_array($agruparPor, ['status_sla', 'responsavel', 'grupo_solucao'], true) ...`, continua idêntico — já opera sobre `$query`, que agora já vem filtrado).

- [ ] **Step 6: Atualizar o call site em `RelatorioSalvoController::executar()`**

Em `app/Http/Controllers/Api/RelatorioSalvoController.php:58-66`, mudar:

```php
        return $controller->responder($relatorioSalvo->filtros, $relatorioSalvo->agrupar_por, $formato);
```

para:

```php
        return $controller->responder($relatorioSalvo->filtros, $relatorioSalvo->agrupar_por, $formato, $request->user());
```

- [ ] **Step 7: Rodar o teste novo e confirmar que passa**

Run: `php artisan test --filter=DashboardRelatorioVisibilidadeTest`
Expected: PASS (3 testes).

- [ ] **Step 8: Rodar Dashboard e Relatório completos, identificar quebras esperadas**

Run: `php artisan test tests/Feature/Dashboard tests/Feature/Relatorios`
Expected: FAIL em exatamente 4 testes — `test_dashboard_returns_flattened_incidente_information`, `test_dashboard_can_combine_filters` (`IncidentesDashboardTest`), `test_agrupar_por_grupo_solucao_counts_closed_incidentes_per_group`, `test_filters_by_grupo_solucao_id` (`RelatorioIncidentesTest`) — mesmo motivo da Task 5: o usuário de teste tem um grupo aleatório diferente do grupo usado nos incidentes desses testes.

- [ ] **Step 9: Corrigir `IncidentesDashboardTest`**

Em `tests/Feature/Dashboard/IncidentesDashboardTest.php`, adicionar o import `use App\Models\GrupoSolucao;` (já importado, linha 8) e trocar a assinatura do helper (linhas 23-35):

```php
    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }
```

por:

```php
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }
```

Em `test_dashboard_returns_flattened_incidente_information` (linhas 42-103), trocar `$token = $this->staffToken(['tickets.view']);` (linha 74) por `$token = $this->staffToken(['tickets.view'], $grupoSolucao);` (a variável `$grupoSolucao` já existe, criada na linha 49).

Em `test_dashboard_can_combine_filters` (linhas 270-283), trocar `$token = $this->staffToken(['tickets.view']);` (linha 275) por `$token = $this->staffToken(['tickets.view'], $grupo);` (a variável `$grupo` já existe, criada na linha 272).

- [ ] **Step 10: Corrigir `RelatorioIncidentesTest`**

Em `tests/Feature/Relatorios/RelatorioIncidentesTest.php`, adicionar o import `use App\Models\GrupoSolucao;` (já importado, linha 8) e trocar a assinatura do helper (linhas 24-38):

```php
    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }
```

por:

```php
    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs, ?GrupoSolucao $grupoSolucao = null, bool $asAdmin = false): array
    {
        $role = Role::factory()->create($asAdmin ? ['slug' => 'admin'] : []);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create($grupoSolucao ? ['grupo_solucao_id' => $grupoSolucao->id] : []);
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }
```

Em `test_agrupar_por_grupo_solucao_counts_closed_incidentes_per_group` (linhas 324-344) — este teste verifica contagem simultânea em DOIS grupos (`Suporte N1` e `Redes`) mais o grupo nulo, o que nenhum usuário não-admin consegue enxergar ao mesmo tempo por padrão; trocar `[$token] = $this->staffToken(['relatorios.view']);` (linha 335) por `[$token] = $this->staffToken(['relatorios.view'], null, true);` (usuário admin, bypass total — é um cenário de relatório consolidado, não de um agente restrito).

Em `test_filters_by_grupo_solucao_id` (linhas 418-433), trocar `[$token] = $this->staffToken(['relatorios.view']);` (linha 424) por `[$token] = $this->staffToken(['relatorios.view'], $grupo);` (a variável `$grupo` já existe, criada na linha 420).

- [ ] **Step 11: Rodar Dashboard e Relatório completos de novo**

Run: `php artisan test tests/Feature/Dashboard tests/Feature/Relatorios`
Expected: PASS — todos os testes.

- [ ] **Step 12: Rodar a suíte completa**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 13: Commit**

```bash
git add app/Models/IncidenteEvento.php app/Http/Controllers/Api/DashboardController.php app/Http/Controllers/Api/RelatorioController.php app/Http/Controllers/Api/RelatorioSalvoController.php tests/Feature/Dashboard/IncidentesDashboardTest.php tests/Feature/Relatorios/RelatorioIncidentesTest.php tests/Feature/Authorization/DashboardRelatorioVisibilidadeTest.php
git commit -m "feat: scope dashboard and relatorio aggregations by visible grupo_solucao"
```

---

### Task 9: Endpoint de administração — permissões por grupo de solução

**Files:**
- Create: `app/Http/Controllers/Api/GrupoSolucaoPermissaoController.php`
- Modify: `routes/api.php:117-121`
- Test: `tests/Feature/GruposSolucao/GrupoSolucaoPermissoesTest.php`

**Interfaces:**
- Consumes: `GrupoSolucao::permissoesLiberadas`/`permissoesBloqueadas` (Task 1).

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature\GruposSolucao;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrupoSolucaoPermissoesTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_shows_padrao_for_every_permission_when_grupo_has_no_override(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        Permission::factory()->create(['slug' => 'tickets.view']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->getJson("/api/grupos-solucao/{$grupo->id}/permissoes", $this->authHeader($token));

        $response->assertOk();
        $estados = collect($response->json('data'))->pluck('estado')->unique();
        $this->assertEqualsCanonicalizing(['padrao'], $estados->all());
    }

    public function test_can_set_liberadas_and_bloqueadas(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $liberada = Permission::factory()->create(['slug' => 'tickets.assign']);
        $bloqueada = Permission::factory()->create(['slug' => 'tickets.manage']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->putJson(
            "/api/grupos-solucao/{$grupo->id}/permissoes",
            ['liberadas' => [$liberada->id], 'bloqueadas' => [$bloqueada->id]],
            $this->authHeader($token)
        );

        $response->assertOk();
        $porId = collect($response->json('data'))->keyBy('id');
        $this->assertSame('liberada', $porId[$liberada->id]['estado']);
        $this->assertSame('bloqueada', $porId[$bloqueada->id]['estado']);

        $this->assertDatabaseHas('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $liberada->id, 'tipo' => 'liberada',
        ]);
        $this->assertDatabaseHas('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $bloqueada->id, 'tipo' => 'bloqueada',
        ]);
    }

    public function test_updating_replaces_the_previous_configuration(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $permission = Permission::factory()->create(['slug' => 'tickets.assign']);
        $grupo->permissoesLiberadas()->attach($permission->id, ['tipo' => 'liberada']);
        $token = $this->staffToken(['grupos_solucao.manage']);

        $this->putJson(
            "/api/grupos-solucao/{$grupo->id}/permissoes",
            ['liberadas' => [], 'bloqueadas' => [$permission->id]],
            $this->authHeader($token)
        )->assertOk();

        $this->assertDatabaseMissing('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $permission->id, 'tipo' => 'liberada',
        ]);
        $this->assertDatabaseHas('grupo_solucao_permissoes', [
            'grupo_solucao_id' => $grupo->id, 'permission_id' => $permission->id, 'tipo' => 'bloqueada',
        ]);
    }

    public function test_rejects_a_permission_id_in_both_lists(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $permission = Permission::factory()->create();
        $token = $this->staffToken(['grupos_solucao.manage']);

        $response = $this->putJson(
            "/api/grupos-solucao/{$grupo->id}/permissoes",
            ['liberadas' => [$permission->id], 'bloqueadas' => [$permission->id]],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('liberadas');
    }

    public function test_view_only_permission_cannot_update_grupo_permissoes(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $token = $this->staffToken(['grupos_solucao.view']);

        $this->putJson("/api/grupos-solucao/{$grupo->id}/permissoes", ['liberadas' => []], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_grupo_permissoes(): void
    {
        $grupo = GrupoSolucao::factory()->create();

        $this->getJson("/api/grupos-solucao/{$grupo->id}/permissoes")->assertStatus(401);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=GrupoSolucaoPermissoesTest`
Expected: FAIL — rota `/api/grupos-solucao/{grupo}/permissoes` ainda não existe (404).

- [ ] **Step 3: Criar o controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GrupoSolucao;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrupoSolucaoPermissaoController extends Controller
{
    public function show(GrupoSolucao $grupo_solucao)
    {
        return response()->json(['data' => $this->payload($grupo_solucao)]);
    }

    public function update(Request $request, GrupoSolucao $grupo_solucao)
    {
        $data = $request->validate([
            'liberadas' => ['sometimes', 'array'],
            'liberadas.*' => ['integer', 'distinct', 'exists:permissions,id'],
            'bloqueadas' => ['sometimes', 'array'],
            'bloqueadas.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $liberadas = $data['liberadas'] ?? [];
        $bloqueadas = $data['bloqueadas'] ?? [];

        if (array_intersect($liberadas, $bloqueadas) !== []) {
            throw ValidationException::withMessages([
                'liberadas' => 'Uma permission não pode estar liberada e bloqueada ao mesmo tempo.',
            ]);
        }

        // Substitui a configuração inteira do grupo numa transação — mais
        // simples e sem risco de colidir com a PK composta
        // (grupo_solucao_id, permission_id) do que tentar sync() nas duas
        // relações (permissoesLiberadas/permissoesBloqueadas) separadamente,
        // que compartilham a mesma tabela física.
        DB::transaction(function () use ($grupo_solucao, $liberadas, $bloqueadas) {
            DB::table('grupo_solucao_permissoes')->where('grupo_solucao_id', $grupo_solucao->id)->delete();

            $linhas = collect($liberadas)->map(fn (int $id) => [
                'grupo_solucao_id' => $grupo_solucao->id,
                'permission_id' => $id,
                'tipo' => 'liberada',
            ])->concat(collect($bloqueadas)->map(fn (int $id) => [
                'grupo_solucao_id' => $grupo_solucao->id,
                'permission_id' => $id,
                'tipo' => 'bloqueada',
            ]));

            if ($linhas->isNotEmpty()) {
                DB::table('grupo_solucao_permissoes')->insert($linhas->all());
            }
        });

        return response()->json(['data' => $this->payload($grupo_solucao->fresh())]);
    }

    private function payload(GrupoSolucao $grupo_solucao): array
    {
        $grupo_solucao->loadMissing('permissoesLiberadas', 'permissoesBloqueadas');

        $liberadasIds = $grupo_solucao->permissoesLiberadas->pluck('id');
        $bloqueadasIds = $grupo_solucao->permissoesBloqueadas->pluck('id');

        return Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
            'id' => $permission->id,
            'name' => $permission->name,
            'slug' => $permission->slug,
            'estado' => match (true) {
                $liberadasIds->contains($permission->id) => 'liberada',
                $bloqueadasIds->contains($permission->id) => 'bloqueada',
                default => 'padrao',
            },
        ])->values()->all();
    }
}
```

- [ ] **Step 4: Adicionar as rotas**

Em `routes/api.php`, dentro do bloco `Route::middleware(['auth:web', 'can:grupos_solucao.manage'])` (linhas 117-121), depois do `->only(['store', 'update', 'destroy'])`:

```php
Route::middleware(['auth:web', 'can:grupos_solucao.manage'])
    ->apiResource('grupos-solucao', GrupoSolucaoController::class)
    ->parameters(['grupos-solucao' => 'grupo_solucao'])
    ->only(['store', 'update', 'destroy']);

Route::middleware(['auth:web', 'can:grupos_solucao.manage'])->group(function () {
    Route::get('/grupos-solucao/{grupo_solucao}/permissoes', [GrupoSolucaoPermissaoController::class, 'show']);
    Route::put('/grupos-solucao/{grupo_solucao}/permissoes', [GrupoSolucaoPermissaoController::class, 'update']);
});
```

E adicionar o import no topo do arquivo, junto aos demais `use App\Http\Controllers\Api\...`:

```php
use App\Http\Controllers\Api\GrupoSolucaoPermissaoController;
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=GrupoSolucaoPermissoesTest`
Expected: PASS (6 testes).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/GrupoSolucaoPermissaoController.php routes/api.php tests/Feature/GruposSolucao/GrupoSolucaoPermissoesTest.php
git commit -m "feat: add admin endpoint to configure grupo_solucao permission overrides"
```

---

### Task 10: Endpoint de administração — visibilidade extra por usuário

**Files:**
- Create: `app/Http/Controllers/Api/UserVisibilidadeController.php`
- Modify: `routes/api.php:55-60`
- Test: `tests/Feature/Users/UserVisibilidadeTest.php`

**Interfaces:**
- Consumes: `User::gruposVisiveisExtra` (Task 2).

- [ ] **Step 1: Escrever o teste (falhando)**

```php
<?php

namespace Tests\Feature\Users;

use App\Models\GrupoSolucao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_shows_no_grupos_extra_by_default(): void
    {
        $user = User::factory()->create();
        $token = $this->staffToken(['users.manage']);

        $response = $this->getJson("/api/users/{$user->id}/grupos-visiveis", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.grupo_solucao_id', $user->grupo_solucao_id)
            ->assertJsonCount(0, 'data.grupos_extra');
    }

    public function test_can_grant_extra_grupos(): void
    {
        $user = User::factory()->create();
        $extra = GrupoSolucao::factory()->create(['nome' => 'Suporte N2']);
        $token = $this->staffToken(['users.manage']);

        $response = $this->putJson(
            "/api/users/{$user->id}/grupos-visiveis",
            ['grupo_solucao_ids' => [$extra->id]],
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonCount(1, 'data.grupos_extra')
            ->assertJsonPath('data.grupos_extra.0.nome', 'Suporte N2');

        $this->assertDatabaseHas('user_grupo_solucao_visibilidade', [
            'user_id' => $user->id, 'grupo_solucao_id' => $extra->id,
        ]);
    }

    public function test_updating_replaces_the_previous_grant_list(): void
    {
        $user = User::factory()->create();
        $antigo = GrupoSolucao::factory()->create();
        $novo = GrupoSolucao::factory()->create();
        $user->gruposVisiveisExtra()->attach($antigo->id);
        $token = $this->staffToken(['users.manage']);

        $this->putJson(
            "/api/users/{$user->id}/grupos-visiveis",
            ['grupo_solucao_ids' => [$novo->id]],
            $this->authHeader($token)
        )->assertOk();

        $this->assertDatabaseMissing('user_grupo_solucao_visibilidade', ['user_id' => $user->id, 'grupo_solucao_id' => $antigo->id]);
        $this->assertDatabaseHas('user_grupo_solucao_visibilidade', ['user_id' => $user->id, 'grupo_solucao_id' => $novo->id]);
    }

    public function test_rejects_granting_the_users_own_grupo_as_extra(): void
    {
        $user = User::factory()->create();
        $token = $this->staffToken(['users.manage']);

        $response = $this->putJson(
            "/api/users/{$user->id}/grupos-visiveis",
            ['grupo_solucao_ids' => [$user->grupo_solucao_id]],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('grupo_solucao_ids.0');
    }

    public function test_view_permission_alone_cannot_update_grupos_visiveis(): void
    {
        $user = User::factory()->create();
        $token = $this->staffToken(['some.other.permission']);

        $this->putJson("/api/users/{$user->id}/grupos-visiveis", ['grupo_solucao_ids' => []], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_grupos_visiveis(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/users/{$user->id}/grupos-visiveis")->assertStatus(401);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `php artisan test --filter=UserVisibilidadeTest`
Expected: FAIL — rota `/api/users/{user}/grupos-visiveis` ainda não existe (404).

- [ ] **Step 3: Criar o controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GrupoSolucao;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserVisibilidadeController extends Controller
{
    public function show(User $user)
    {
        return response()->json(['data' => $this->payload($user)]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'grupo_solucao_ids' => ['present', 'array'],
            'grupo_solucao_ids.*' => [
                'integer',
                'distinct',
                'exists:grupos_solucao,id',
                // O grupo próprio já é implícito (User::visibleGrupoSolucaoIds()
                // sempre inclui grupo_solucao_id) — incluir aqui de novo seria
                // redundante e confundiria a UI de admin.
                Rule::notIn([$user->grupo_solucao_id]),
            ],
        ]);

        $user->gruposVisiveisExtra()->sync($data['grupo_solucao_ids']);

        return response()->json(['data' => $this->payload($user->fresh())]);
    }

    private function payload(User $user): array
    {
        $user->loadMissing('grupoSolucao', 'gruposVisiveisExtra');

        return [
            'grupo_solucao_id' => $user->grupo_solucao_id,
            'grupo_solucao' => $user->grupoSolucao ? [
                'id' => $user->grupoSolucao->id,
                'nome' => $user->grupoSolucao->nome,
            ] : null,
            'grupos_extra' => $user->gruposVisiveisExtra->map(fn (GrupoSolucao $g) => [
                'id' => $g->id,
                'nome' => $g->nome,
            ])->values(),
        ];
    }
}
```

- [ ] **Step 4: Adicionar as rotas**

Em `routes/api.php`, dentro do bloco `Route::middleware(['auth:web', 'can:users.manage'])` (linhas 55-60), adicionar:

```php
Route::middleware(['auth:web', 'can:users.manage'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::post('/users/{user}/convite', [UserController::class, 'enviarConvite'])
        ->middleware('throttle:convite');
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/users/{user}/grupos-visiveis', [UserVisibilidadeController::class, 'show']);
    Route::put('/users/{user}/grupos-visiveis', [UserVisibilidadeController::class, 'update']);
});
```

E adicionar o import no topo do arquivo:

```php
use App\Http\Controllers\Api\UserVisibilidadeController;
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `php artisan test --filter=UserVisibilidadeTest`
Expected: PASS (6 testes).

- [ ] **Step 6: Rodar a suíte completa (checagem final de regressão)**

Run: `php artisan test`
Expected: PASS — todos os testes do projeto, incluindo os 10 arquivos novos desta feature e todos os arquivos existentes tocados (`IncidenteCrudTest`, `IncidentesDashboardTest`, `RelatorioIncidentesTest`).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/UserVisibilidadeController.php routes/api.php tests/Feature/Users/UserVisibilidadeTest.php
git commit -m "feat: add admin endpoint to grant users extra incidente visibility"
```
