# SLA por Categorização (Item) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vincular a prioridade de SLA (Urgente/Alta/Média/Baixa) ao cadastro de `Item`, para que ao abrir um incidente para aquele item a prioridade já venha pré-preenchida como padrão — mantendo o cálculo de prazos existente (`Client::resolvedSlaFor()`) intacto, mas restringindo a personalização dessa prioridade a usuários Admin e recalculando os prazos quando a categorização de um incidente aberto muda.

**Architecture:** `Item` ganha uma coluna nullable `prioridade_padrao` (um dos 4 valores de `PoliticaSla::PRIORIDADES`, mesmo enum-em-string já usado em `Incidente.prioridade` e `PoliticaSla.prioridade` — sem tabela nova, sem FK direta pra uma `PoliticaSla` específica). `IncidenteController` ganha um método privado (`prioridadeEfetivaOuNull()`) que resolve a prioridade final de um incidente a partir do item vinculado e do valor explícito enviado no request, barrando com 422 qualquer tentativa de um usuário não-Admin de enviar uma prioridade diferente do padrão do item. `store()` passa a exigir `prioridade` apenas quando o item não tiver padrão (`Rule::requiredIf`); `update()` passa a recalcular `prazo_resposta`/`prazo_resolucao` (via `calcularPrazosSla()`, ajustado pra resetar a `null` quando não há política aplicável) sempre que `item_id` ou `prioridade` fizerem parte do payload.

**Tech Stack:** Laravel 13 (Octane/FrankenPHP), PHPUnit (`RefreshDatabase`), Sanctum (tokens de teste via `createToken()`).

**Spec:** Relatório "Estudo de Viabilidade: SLA Vinculada à Categorização" (Claude Docs, decisões do PO registradas em 19/09/2026) + `BACKEND_SPECS.md` §3.1 (`itens`, `incidentes`) e §3.4.7 (endpoints de `Incidente`), que este plano também atualiza.

## Global Constraints

- SLA vinculada apenas ao nível de **Item** (não Categoria/Subcategoria) — decisão do PO.
- Apenas usuários com `isAdmin() === true` podem enviar uma `prioridade` diferente do `prioridade_padrao` do item vinculado — qualquer outro staff que tente recebe 422 em `prioridade`. Item sem `prioridade_padrao` (`null`) mantém o comportamento atual (prioridade sempre livre, obrigatória).
- Mudar a categorização (`item_id`) de um incidente já aberto **recalcula** `prazo_resposta`/`prazo_resolucao` a partir da prioridade efetiva resultante — o mesmo vale para uma personalização de `prioridade` feita por um Admin.
- Sistema ainda em prototipação (confirmado pelo PO): sem necessidade de migração de dados/compatibilidade retroativa — a seeder (`CategoriasSeeder`) pode ser reescrita livremente para refletir a nova coluna.
- Nomes de domínio em português (`prioridade_padrao`), comentários só quando explicam um "porquê" não óbvio, mesmo padrão do restante do projeto (ver `app/Models/Incidente.php`, `app/Http/Controllers/Api/IncidenteController.php`).
- Nenhuma permission nova — os endpoints reaproveitam `categorias.manage`/`categorias.view` (Item) e `tickets.manage`/`tickets.view` (Incidente), já existentes.

---

### Task 1: `Item.prioridade_padrao` — schema, model, API e seeder

**Files:**
- Create: `database/migrations/2026_09_19_120000_add_prioridade_padrao_to_itens_table.php`
- Modify: `app/Models/Item.php:13` (atributo `#[Fillable(...)]`)
- Modify: `app/Http/Controllers/Api/ItemController.php:1-84` (import + `rules()`)
- Modify: `app/Http/Resources/ItemResource.php:15-29`
- Modify: `database/seeders/CategoriasSeeder.php`
- Modify: `BACKEND_SPECS.md:380-388` (tabela `itens`)
- Test: `tests/Feature/Categorias/ItemCrudTest.php`

**Interfaces:**
- Produces: coluna `itens.prioridade_padrao` (`string`, nullable, um de `PoliticaSla::PRIORIDADES`), exposta em `ItemResource` como `prioridade_padrao`. Consumida pela Task 2 (`IncidenteController::prioridadeEfetivaOuNull()`, que lê `Item::prioridade_padrao`).

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
        Schema::table('itens', function (Blueprint $table) {
            // Prioridade de SLA sugerida ao abrir um incidente pra este item
            // — nullable: item sem prioridade padrão mantém o fluxo atual
            // (prioridade sempre escolhida livremente na abertura). Mesmo
            // enum-em-string de PoliticaSla::PRIORIDADES, sem FK direta pra
            // uma PoliticaSla específica (ver BACKEND_SPECS.md §3.1).
            $table->string('prioridade_padrao')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('itens', function (Blueprint $table) {
            $table->dropColumn('prioridade_padrao');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate` (no ambiente Docker do projeto: `docker compose exec app php artisan migrate`)
Expected: coluna `prioridade_padrao` criada em `itens` sem erro.

- [ ] **Step 3: Escrever os testes de API (falhando)**

Adicionar em `tests/Feature/Categorias/ItemCrudTest.php`, logo após `test_admin_can_create_an_item`:

```php
    public function test_admin_can_create_an_item_with_a_prioridade_padrao(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [
            'subcategoria_id' => $subcategoria->id,
            'nome' => 'Sem toner',
            'prioridade_padrao' => 'urgente',
        ], $this->authHeader($token));

        $response->assertCreated()->assertJsonPath('data.prioridade_padrao', 'urgente');
        $this->assertDatabaseHas('itens', ['nome' => 'Sem toner', 'prioridade_padrao' => 'urgente']);
    }

    public function test_creating_an_item_without_prioridade_padrao_leaves_it_null(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [
            'subcategoria_id' => $subcategoria->id,
            'nome' => 'Sem toner',
        ], $this->authHeader($token));

        $response->assertCreated()->assertJsonPath('data.prioridade_padrao', null);
    }

    public function test_creating_item_rejects_invalid_prioridade_padrao(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [
            'subcategoria_id' => $subcategoria->id,
            'nome' => 'Sem toner',
            'prioridade_padrao' => 'gigante',
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade_padrao');
    }

    public function test_admin_can_update_an_item_prioridade_padrao(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => null]);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->putJson("/api/itens/{$item->id}", [
            'subcategoria_id' => $item->subcategoria_id,
            'nome' => $item->nome,
            'prioridade_padrao' => 'media',
        ], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.prioridade_padrao', 'media');
    }
```

- [ ] **Step 4: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=ItemCrudTest`
Expected: FAIL — `prioridade_padrao` ainda não é mass-assignable nem validado, `ItemResource` ainda não expõe o campo (`assertJsonPath('data.prioridade_padrao', ...)` falha ou o campo simplesmente não existe/não é aceito).

- [ ] **Step 5: Liberar `prioridade_padrao` no `#[Fillable(...)]` do `Item`**

Em `app/Models/Item.php:13`, trocar:

```php
#[Fillable(['subcategoria_id', 'nome', 'ativo'])]
```

por:

```php
#[Fillable(['subcategoria_id', 'nome', 'ativo', 'prioridade_padrao'])]
```

- [ ] **Step 6: Validar `prioridade_padrao` em `ItemController::rules()`**

Em `app/Http/Controllers/Api/ItemController.php`, adicionar o import:

```php
use App\Models\PoliticaSla;
```

E em `rules()` (linhas 69-83), acrescentar a chave (depois de `'ativo'`):

```php
            'ativo' => ['boolean'],
            'prioridade_padrao' => ['nullable', 'string', Rule::in(PoliticaSla::PRIORIDADES)],
```

- [ ] **Step 7: Expor o campo em `ItemResource`**

Em `app/Http/Resources/ItemResource.php:15-29`, acrescentar (depois de `'ativo'`):

```php
            'ativo' => $this->ativo,
            'prioridade_padrao' => $this->prioridade_padrao,
```

- [ ] **Step 8: Rodar os testes e confirmar que passam**

Run: `php artisan test --filter=ItemCrudTest`
Expected: PASS.

- [ ] **Step 9: Popular a seeder com dados de exemplo**

Sistema ainda em prototipação (PO autorizou reescrever a seeder livremente) — em vez de deixar todo item com `prioridade_padrao` nulo, ciclar pelas 4 prioridades pra ter dado de demonstração variado. Em `database/seeders/CategoriasSeeder.php`, adicionar o import:

```php
use App\Models\PoliticaSla;
```

E trocar o loop de itens (linhas 59-63) de:

```php
                foreach ($itens as $itemNome) {
                    $subcategoria->itens()->updateOrCreate(
                        ['nome' => $itemNome],
                        ['ativo' => true]
                    );
                }
```

para:

```php
                foreach ($itens as $indice => $itemNome) {
                    $subcategoria->itens()->updateOrCreate(
                        ['nome' => $itemNome],
                        [
                            'ativo' => true,
                            'prioridade_padrao' => PoliticaSla::PRIORIDADES[$indice % count(PoliticaSla::PRIORIDADES)],
                        ]
                    );
                }
```

- [ ] **Step 10: Rodar a seeder e conferir manualmente**

Run: `php artisan db:seed --class=CategoriasSeeder` (ou `docker compose exec app php artisan db:seed --class=CategoriasSeeder`)
Expected: sem erro; `select nome, prioridade_padrao from itens limit 5;` mostra prioridades variadas (não todas nulas).

- [ ] **Step 11: Atualizar `BACKEND_SPECS.md`**

Em `BACKEND_SPECS.md`, na tabela de `itens` (linhas 380-388), adicionar uma linha depois de `nome`:

```
| `prioridade_padrao` | `string` nullable | um de `PoliticaSla::PRIORIDADES`, validado (`Rule::in`) — prioridade de SLA sugerida ao abrir um incidente pra este item (ver nota de "SLA por Categorização" na tabela `incidentes`); `null` mantém o fluxo anterior (prioridade sempre livre) |
```

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_09_19_120000_add_prioridade_padrao_to_itens_table.php \
  app/Models/Item.php app/Http/Controllers/Api/ItemController.php app/Http/Resources/ItemResource.php \
  database/seeders/CategoriasSeeder.php tests/Feature/Categorias/ItemCrudTest.php BACKEND_SPECS.md
git commit -m "feat: add prioridade_padrao to Item for SLA por categorização"
```

---

### Task 2: `IncidenteController` — resolução de prioridade por Item, personalização restrita a Admin e recálculo de prazos

**Files:**
- Modify: `app/Http/Controllers/Api/IncidenteController.php:1-384`
- Modify: `BACKEND_SPECS.md:404-436` (tabela `incidentes` + nota de cálculo de SLA)
- Test: `tests/Feature/Incidentes/IncidenteSlaPorCategoriaTest.php` (novo)

**Interfaces:**
- Consumes: `Item::prioridade_padrao` (Task 1), `User::isAdmin(): bool` (já existente em `app/Models/User.php:87`), `Client::resolvedSlaFor(string $prioridade): ?PoliticaSla` (já existente).
- Produces: `IncidenteController::prioridadeEfetivaOuNull(Request $request, array $data, ?Item $item): ?string` (privado) — usado só dentro da própria classe, não é uma interface pública pra outras tasks.

- [ ] **Step 1: Escrever os testes de comportamento (falhando)**

Criar `tests/Feature/Incidentes/IncidenteSlaPorCategoriaTest.php`:

```php
<?php

namespace Tests\Feature\Incidentes;

use App\Models\Client;
use App\Models\Customer;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Permission;
use App\Models\PoliticaSla;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteSlaPorCategoriaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     */
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

    /**
     * @param  string[]  $permissionSlugs
     */
    private function adminToken(array $permissionSlugs = ['tickets.manage']): string
    {
        $role = Role::factory()->create(['slug' => 'admin']);

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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'titulo' => 'Impressora não liga',
            'descricao' => 'Tentei ligar e nada acontece.',
            'origem' => 'portal',
        ], $overrides);
    }

    public function test_creating_incidente_defaults_prioridade_from_the_item_prioridade_padrao(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'urgente']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id]),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.prioridade', 'urgente');
    }

    public function test_creating_incidente_without_item_default_still_requires_explicit_prioridade(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => null]);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id]),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_non_admin_can_send_a_prioridade_that_matches_the_item_default(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'alta']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id, 'prioridade' => 'alta']),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.prioridade', 'alta');
    }

    public function test_non_admin_cannot_override_the_item_default_prioridade(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'baixa']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id, 'prioridade' => 'urgente']),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_admin_can_personalize_the_prioridade_away_from_the_item_default(): void
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'baixa']);
        $token = $this->adminToken();

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['item_id' => $item->id, 'prioridade' => 'urgente']),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.prioridade', 'urgente');
    }

    public function test_updating_item_id_applies_the_new_item_default_and_recalculates_deadlines(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => null, 'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15, 'tempo_resolucao_minutos' => 240,
        ]);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id, 'item_id' => null, 'prioridade' => 'baixa',
        ]);
        $novoItem = Item::factory()->create(['prioridade_padrao' => 'urgente']);
        $token = $this->staffToken(['tickets.manage']);

        $response = $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['item_id' => $novoItem->id],
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonPath('data.prioridade', 'urgente');
        $fresh = $incidente->fresh();
        $this->assertSame('urgente', $fresh->prioridade);
        $this->assertSame(
            $fresh->created_at->copy()->addMinutes(15)->timestamp,
            $fresh->prazo_resposta->timestamp
        );
    }

    public function test_admin_overriding_prioridade_directly_recalculates_deadlines(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => null, 'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15, 'tempo_resolucao_minutos' => 240,
        ]);
        $item = Item::factory()->create(['prioridade_padrao' => 'baixa']);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id, 'item_id' => $item->id, 'prioridade' => 'baixa',
        ]);
        $token = $this->adminToken();

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['prioridade' => 'urgente'],
            $this->authHeader($token)
        )->assertOk();

        $fresh = $incidente->fresh();
        $this->assertSame(
            $fresh->created_at->copy()->addMinutes(15)->timestamp,
            $fresh->prazo_resposta->timestamp
        );
    }

    public function test_recalculated_deadlines_become_null_when_the_new_prioridade_has_no_applicable_policy(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => null, 'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15, 'tempo_resolucao_minutos' => 240,
        ]);
        $token = $this->adminToken();

        $createResponse = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['customer_id' => $customer->id, 'prioridade' => 'urgente']),
            $this->authHeader($token)
        );
        $incidenteId = $createResponse->json('data.id');
        $this->assertNotNull(Incidente::find($incidenteId)->prazo_resposta);

        $this->putJson(
            "/api/incidentes/{$incidenteId}",
            ['prioridade' => 'baixa'],
            $this->authHeader($token)
        )->assertOk();

        $fresh = Incidente::find($incidenteId);
        $this->assertNull($fresh->prazo_resposta);
        $this->assertNull($fresh->prazo_resolucao);
    }

    public function test_updating_unrelated_field_does_not_recalculate_deadlines(): void
    {
        $incidente = Incidente::factory()->create();
        $prazoFixo = now()->subDays(3);
        $incidente->forceFill(['prazo_resposta' => $prazoFixo, 'prazo_resolucao' => $prazoFixo])->save();
        $token = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertTrue($incidente->fresh()->prazo_resposta->equalTo($prazoFixo));
    }
}
```

- [ ] **Step 2: Rodar os testes e confirmar que falham**

Run: `php artisan test --filter=IncidenteSlaPorCategoriaTest`
Expected: FAIL em pelo menos: `test_creating_incidente_defaults_prioridade_from_the_item_prioridade_padrao` (prioridade continua obrigatória e não é preenchida a partir do item), `test_non_admin_cannot_override_the_item_default_prioridade` (nada bloqueia hoje), `test_updating_item_id_applies_the_new_item_default_and_recalculates_deadlines` e `test_admin_overriding_prioridade_directly_recalculates_deadlines` (update() não recalcula prazos hoje).

- [ ] **Step 3: Rodar a suíte completa de Incidentes pra ter uma baseline**

Run: `php artisan test tests/Feature/Incidentes`
Expected: `IncidenteCrudTest`, `IncidenteSlaTest` etc. PASS (comportamento atual intacto); só `IncidenteSlaPorCategoriaTest` (Step 2) falha. Essa baseline é o que a Step 8 vai reconfirmar depois da implementação — nenhuma dessas suítes deve regredir.

- [ ] **Step 4: Adicionar o import de `ValidationException`**

Em `app/Http/Controllers/Api/IncidenteController.php`, no bloco de `use` (linhas 3-18), adicionar:

```php
use Illuminate\Validation\ValidationException;
```

- [ ] **Step 5: Implementar `prioridadeEfetivaOuNull()`**

Adicionar como novo método privado, logo antes de `calcularPrazosSla()` (linha 294):

```php
    /**
     * Resolve a prioridade efetiva de um incidente a partir do
     * `Item::prioridade_padrao` vinculado e do valor explícito enviado no
     * request. Sem `prioridade` explícita, devolve o padrão do item (ou
     * `null`, se o item não tiver um — cabe ao chamador decidir o que fazer
     * nesse caso: `store()` já garante via `Rule::requiredIf` que isso só
     * acontece quando há padrão; `update()` cai de volta pra prioridade
     * atual do incidente). Só Admin pode enviar uma prioridade diferente do
     * padrão do item (personalização) — qualquer outro staff que tente
     * recebe 422 em 'prioridade' (ver design "SLA por Categorização" no
     * BACKEND_SPECS.md §3.1).
     */
    private function prioridadeEfetivaOuNull(Request $request, array $data, ?Item $item): ?string
    {
        $padrao = $item?->prioridade_padrao;

        if (! array_key_exists('prioridade', $data)) {
            return $padrao;
        }

        $explicita = $data['prioridade'];

        if ($padrao !== null && $explicita !== $padrao && ! $request->user()->isAdmin()) {
            throw ValidationException::withMessages([
                'prioridade' => 'Apenas administradores podem personalizar a prioridade quando o item já possui uma SLA padrão.',
            ]);
        }

        return $explicita;
    }
```

- [ ] **Step 6: Ajustar `calcularPrazosSla()` pra ser idempotente (resetar a `null` sem política aplicável)**

Em `app/Http/Controllers/Api/IncidenteController.php:288-306`, trocar o método (e seu comentário) de:

```php
    /**
     * Calcula e congela prazo_resposta/prazo_resolucao a partir da política
     * de SLA aplicável no momento da abertura (ver Client::resolvedSlaFor())
     * — nunca recalculado depois, mesmo que a política mude. Fica `null`
     * (sem_sla) se não houver política aplicável pra essa prioridade.
     */
    private function calcularPrazosSla(Incidente $incidente): void
    {
        $politica = $incidente->loadMissing('customer.client')
            ->customer->client?->resolvedSlaFor($incidente->prioridade);

        if ($politica === null) {
            return;
        }

        $incidente->prazo_resposta = $incidente->created_at->copy()->addMinutes($politica->tempo_resposta_minutos);
        $incidente->prazo_resolucao = $incidente->created_at->copy()->addMinutes($politica->tempo_resolucao_minutos);
        $incidente->save();
    }
```

para:

```php
    /**
     * Calcula prazo_resposta/prazo_resolucao a partir da política de SLA
     * aplicável (ver Client::resolvedSlaFor()) e os congela — chamado uma
     * única vez no store() (nunca recalculado por mudança de política
     * depois), e de novo no update() quando `item_id`/`prioridade` mudam
     * (ver "SLA por Categorização" no BACKEND_SPECS.md §3.1). Idempotente:
     * sem política aplicável pra prioridade atual, os dois campos voltam
     * explicitamente pra `null` (sem_sla) — necessário pro recálculo do
     * update() não deixar um prazo da prioridade *anterior* como lixo.
     */
    private function calcularPrazosSla(Incidente $incidente): void
    {
        $politica = $incidente->loadMissing('customer.client')
            ->customer->client?->resolvedSlaFor($incidente->prioridade);

        $incidente->prazo_resposta = $politica
            ? $incidente->created_at->copy()->addMinutes($politica->tempo_resposta_minutos)
            : null;
        $incidente->prazo_resolucao = $politica
            ? $incidente->created_at->copy()->addMinutes($politica->tempo_resolucao_minutos)
            : null;
        $incidente->save();
    }
```

- [ ] **Step 7: Ligar a resolução de prioridade em `store()` e `update()`**

Em `store()` (`app/Http/Controllers/Api/IncidenteController.php:53-64`), trocar a regra de `prioridade` de:

```php
            'prioridade' => ['required', 'string', Rule::in(PoliticaSla::PRIORIDADES)],
```

para:

```php
            'prioridade' => [
                Rule::requiredIf(fn () => ! Item::query()->find($request->input('item_id'))?->prioridade_padrao),
                'string', Rule::in(PoliticaSla::PRIORIDADES),
            ],
```

Logo depois do `$data = $request->validate([...]);` de `store()` (antes de `$descricaoTexto = $data['descricao'];`), adicionar:

```php
        $item = ($data['item_id'] ?? null) ? Item::query()->find($data['item_id']) : null;
        $data['prioridade'] = $this->prioridadeEfetivaOuNull($request, $data, $item);

```

Em `update()` (`app/Http/Controllers/Api/IncidenteController.php:108-134`), a regra de `prioridade` já é `['sometimes', 'required', 'string', Rule::in(...)]` — não muda. Logo depois do `$data = $request->validate([...]);` de `update()` (antes de `$grupoAnteriorId = $incidente->grupo_solucao_id;`), adicionar:

```php
        $recalcularSla = array_key_exists('item_id', $data) || array_key_exists('prioridade', $data);

        if ($recalcularSla) {
            $itemIdEfetivo = array_key_exists('item_id', $data) ? $data['item_id'] : $incidente->item_id;
            $item = $itemIdEfetivo ? Item::query()->find($itemIdEfetivo) : null;
            $data['prioridade'] = $this->prioridadeEfetivaOuNull($request, $data, $item) ?? $incidente->prioridade;
        }

```

Adicionar `$recalcularSla` à lista de `use (...)` da closure da transação de `update()` (linha 135-146), e logo depois de `$incidente->update($data);` (dentro da closure), adicionar:

```php
            if ($recalcularSla) {
                $this->calcularPrazosSla($incidente);
            }

```

- [ ] **Step 8: Rodar a suíte de Incidentes inteira e confirmar que tudo passa**

Run: `php artisan test tests/Feature/Incidentes`
Expected: PASS — `IncidenteSlaPorCategoriaTest` (Step 1) e toda a baseline da Step 3 (`IncidenteCrudTest`, `IncidenteSlaTest`, `IncidenteDescricaoCrudTest`, `IncidenteAnexoCrudTest`) sem regressão.

- [ ] **Step 9: Rodar a suíte completa do projeto**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 10: Atualizar `BACKEND_SPECS.md`**

Na tabela de `incidentes` (`BACKEND_SPECS.md:414`), trocar a linha de `prioridade` de:

```
| `prioridade` | `string` | reaproveita `PoliticaSla::PRIORIDADES` (`baixa`\|`media`\|`alta`\|`urgente`) — sem duplicar a lista de constantes |
```

para:

```
| `prioridade` | `string` | reaproveita `PoliticaSla::PRIORIDADES` (`baixa`\|`media`\|`alta`\|`urgente`) — sem duplicar a lista de constantes. Padrão vem de `Item::prioridade_padrao` quando o incidente tem `item_id`; só Admin pode enviar uma prioridade diferente desse padrão (ver nota "SLA por Categorização" abaixo) |
```

E, logo depois da nota de "Cálculo de SLA" existente (`BACKEND_SPECS.md:424-435`), adicionar uma nova nota:

```
> 📌 **SLA por Categorização (`Item::prioridade_padrao`).** Cada `Item` pode ter uma
> `prioridade_padrao` (uma de `PoliticaSla::PRIORIDADES`, nullable). Ao abrir um incidente pra um
> item com padrão definido, a prioridade já vem pré-preenchida; só um usuário `isAdmin()` pode
> enviar uma prioridade diferente desse padrão (`IncidenteController::prioridadeEfetivaOuNull()`) —
> qualquer outro staff que tente recebe 422 em `prioridade`. Item sem `prioridade_padrao` mantém o
> fluxo anterior (prioridade sempre obrigatória e livre). Diferente do resto do cálculo de SLA
> (congelado na abertura, nunca recalculado), mudar `item_id` ou `prioridade` num incidente já
> aberto **recalcula** `prazo_resposta`/`prazo_resolucao` (`calcularPrazosSla()` roda de novo em
> `update()` nesses dois casos) — decisão explícita do PO, diferente do restante dos campos do
> incidente, que nunca reabrem esse cálculo.
```

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/Api/IncidenteController.php tests/Feature/Incidentes/IncidenteSlaPorCategoriaTest.php BACKEND_SPECS.md
git commit -m "feat: default incidente prioridade from item, restrict personalization to admin, recalculate sla on category change"
```

---

## Self-Review

**Cobertura do spec (decisões do PO):**
1. SLA só no nível de Item → Task 1 (coluna só em `itens`, não em `categorias`/`subcategorias`). ✅
2. Só Admin personaliza → Task 2, Step 5 (`prioridadeEfetivaOuNull()`) + testes `test_non_admin_cannot_override_the_item_default_prioridade` / `test_admin_can_personalize_the_prioridade_away_from_the_item_default`. ✅
3. Recalcular prazos ao mudar categorização → Task 2, Step 6-7 (`calcularPrazosSla()` idempotente + chamada em `update()`) + teste `test_updating_item_id_applies_the_new_item_default_and_recalculates_deadlines`. ✅
4. Liberdade pra reestruturar schema/seeders (prototipação) → Task 1, Step 9 (seeder reescrita, sem preocupação de migração de dados). ✅

**Placeholders:** nenhum "TODO"/"implementar depois" — todo step tem código completo (migration, testes, métodos).

**Consistência de tipos/assinaturas:** `prioridadeEfetivaOuNull(Request $request, array $data, ?Item $item): ?string` é a única assinatura nova, usada nos dois pontos de chamada (`store()`/`update()`) com os mesmos nomes de parâmetro; `calcularPrazosSla(Incidente $incidente): void` mantém a assinatura existente, só o corpo muda.
