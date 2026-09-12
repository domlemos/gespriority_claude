# Autorização e visibilidade por grupo de solução — Design

## Contexto

O RBAC atual (`BACKEND_SPECS.md` §1.3) resolve autorização inteiramente
por `Role`: `User::hasPermission(slug)` verifica se algum papel do
usuário concede aquela permission, e `AuthServiceProvider::boot()`
liga isso ao `Gate::before()`. Não existe nenhuma checagem por
instância (Policy) além do que a própria spec já sinalizava como
pendência ("Autorização por recurso — ainda não implementado neste
módulo").

Na prática isso significa duas lacunas concretas, confirmadas lendo
`IncidenteController`:

1. **Permissão é só por Role, sem exceção por grupo.** Um `agente`
   tem `tickets.manage` seedado globalmente (`RolesAndPermissionsSeeder`)
   — não há como um admin liberar uma permission extra só para um
   grupo específico, nem bloquear uma permission que o Role concede
   para um grupo específico.
2. **Não existe escopo de visibilidade de dados.** `IncidenteController::index()`
   não filtra por `grupo_solucao_id` nem por `responsavel_id` — qualquer
   staff com `tickets.view`/`tickets.manage` enxerga e edita **todos**
   os incidentes do sistema, de qualquer grupo.

O pedido é uma seção de administração (na SPA Vue, repositório
separado — este repo é API-only) para um admin estabelecer ou
restringir esses dois eixos por grupo de solução cadastrado.

## Escopo

Inclui:
1. Modelo de dados para exceções de permission por `grupo_solucao`
   (liberar extra / bloquear) e para visibilidade extra de incidentes
   por usuário.
2. Atualização de `User::hasPermission()` para considerar essas
   exceções, com bypass total para o papel `admin`.
3. Escopo de visibilidade de incidentes (`Incidente::scopeVisiveisPara()`)
   aplicado em `index()`, mais uma `IncidentePolicy` aplicada em
   `show()`/`update()` e nos recursos aninhados (`descricoes`, `anexos`),
   com o mesmo bypass para `admin`.
4. Dois endpoints novos de administração (grupo→permissões,
   usuário→grupos visíveis extras), reaproveitando permissions já
   existentes (`grupos_solucao.manage`, `users.manage`).
5. Duas migrations novas. Sem mudança de schema em tabelas existentes,
   sem mudança nos seeders.
6. Plano de UI (desenho, não implementação — fica para o repositório
   Vue) para a nova seção de administração.

Não inclui (fora de escopo / YAGNI):
- Portal do `Customer` ver/abrir os próprios incidentes — não existe
  hoje, é uma feature separada, não faz parte desta autorização por
  grupo (que é só sobre `User`/guard `web`).
- Restringir a atribuição de `responsavel_id` a usuários do mesmo
  grupo visível — fora do pedido original, não foi levantado.
- Cache de permissões — o problema de staleness do singleton do
  `spatie/laravel-permission` que motivou a implementação própria
  (§1.3) não muda aqui; a resolução continua por instância de request.
- Implementação da UI em si (SPA Vue fica em repositório separado,
  inacessível a partir deste projeto) — aqui entra só o desenho da
  tela, para orientar quem for implementá-la lá.

## Decisões já tomadas com o usuário

- **Combinação Role×Grupo para permissions:** híbrida. Efetivo =
  `(permissions do Role ∪ liberações do grupo) - bloqueios do grupo`,
  com bloqueio sempre vencendo. Grupo sem nenhuma configuração = sem
  efeito nenhum (comportamento idêntico ao atual).
- **Escopo padrão de visibilidade de incidentes:** cada usuário só
  enxerga incidentes do próprio `grupo_solucao_id`, por padrão.
  Concessão de acesso extra é **por usuário** (não por grupo inteiro),
  para permitir exceções individuais (ex.: agente sênior cobrindo dois
  times) sem mudar a visibilidade de todo o grupo dele.
- **Papel `admin` tem bypass total nos dois eixos** (permissions e
  visibilidade) — nunca é afetado por bloqueio de grupo nem por escopo
  de visibilidade, preservando o comportamento atual ("admin vê/pode
  tudo") e evitando lockout do sistema por má configuração.
- **Incidentes sem `grupo_solucao_id` (fila de triagem pendente)**
  continuam visíveis a todo staff com `tickets.view`, independente do
  grupo dele — senão ninguém conseguiria triar um chamado recém-criado.

## 1. Modelo de dados

### `grupo_solucao_permissoes` (nova tabela)

| Coluna | Tipo | Notas |
|---|---|---|
| `grupo_solucao_id` | `bigint` FK → `grupos_solucao.id` | `cascadeOnDelete` |
| `permission_id` | `bigint` FK → `permissions.id` | `cascadeOnDelete` |
| `tipo` | `string` | um de `liberada`\|`bloqueada` |
| — | PK composta `(grupo_solucao_id, permission_id)` | sem `id`/timestamps, mesmo padrão de `permission_role`/`role_user`. A PK composta por si só já impede que a mesma permission apareça duas vezes para o mesmo grupo — logo impede o estado contraditório "liberada e bloqueada ao mesmo tempo". |

### `user_grupo_solucao_visibilidade` (nova tabela)

| Coluna | Tipo | Notas |
|---|---|---|
| `user_id` | `bigint` FK → `users.id` | `cascadeOnDelete` |
| `grupo_solucao_id` | `bigint` FK → `grupos_solucao.id` | `cascadeOnDelete` |
| — | PK composta `(user_id, grupo_solucao_id)` | sem `id`/timestamps. Representa "grupo extra que este usuário também enxerga", além do próprio `users.grupo_solucao_id`. |

### Relações novas nos models

- `GrupoSolucao::permissoesLiberadas()` / `permissoesBloqueadas()`:
  `belongsToMany(Permission::class, 'grupo_solucao_permissoes')->wherePivot('tipo', ...)`.
- `User::gruposVisiveisExtra()`: `belongsToMany(GrupoSolucao::class, 'user_grupo_solucao_visibilidade')`.

## 2. Autorização (`User::hasPermission`)

```php
public function isAdmin(): bool
{
    return $this->loadMissing('roles')->roles->contains('slug', 'admin');
}

public function hasPermission(string $slug): bool
{
    $this->loadMissing('roles.permissions');
    $viaRole = $this->roles->pluck('permissions')->flatten()->pluck('slug')->contains($slug);

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

`Gate::before()` em `AuthServiceProvider` não muda — já delega para
`hasPermission()`.

## 3. Visibilidade de incidentes

`User::visibleGrupoSolucaoIds(): ?array` — retorna `null` (sentinela
"sem restrição") se `isAdmin()`; caso contrário, `[grupo_solucao_id
próprio, ...ids de gruposVisiveisExtra]`.

```php
// Incidente
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

Aplicado em `IncidenteController::index()`:
`Incidente::query()->visiveisPara($request->user())->filtros($filtros)->...`.

Nova `App\Policies\IncidentePolicy` (descoberta automática pelo
Laravel, sem registro manual — convenção `Model` → `{Model}Policy`):

```php
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
```

Wiring:
- `IncidenteController::show()`/`update()`: `$this->authorize('view'|'update', $incidente)`.
- `IncidenteDescricaoController` e `AnexoController` (ambos aninhados
  em `/incidentes/{incidente}/...`): `$this->authorize('view', $incidente)`
  no início de cada action, para que o escopo não seja contornável
  acessando o feed/anexos de um incidente fora de alcance diretamente
  pelo ID do incidente pai.

`update()` usa a mesma regra de `view()` — não há necessidade
levantada de um escopo mais estrito só para edição.

## 4. Endpoints de administração

- `GET /grupos-solucao/{grupo}/permissoes` — retorna o catálogo de
  permissions com o estado atual (`padrao`\|`liberada`\|`bloqueada`)
  para aquele grupo.
- `PUT /grupos-solucao/{grupo}/permissoes` — payload
  `{liberadas: [permission_id...], bloqueadas: [permission_id...]}`;
  sincroniza (`sync`) as duas listas na tabela `grupo_solucao_permissoes`
  (uma como `tipo=liberada`, outra `tipo=bloqueada`). Validação:
  `liberadas` e `bloqueadas` devem ser disjuntas (nenhum `permission_id`
  nos dois arrays do mesmo payload) — `422` se violar.
  Guardado por `can:grupos_solucao.manage` (permission já existente).
- `GET /users/{user}/grupos-visiveis` — retorna os grupos extras
  atuais do usuário (mais o grupo próprio, só para contexto/exibição).
- `PUT /users/{user}/grupos-visiveis` — payload
  `{grupo_solucao_ids: [...]}`; sincroniza `user_grupo_solucao_visibilidade`.
  Validação: não pode incluir o próprio `grupo_solucao_id` do usuário
  (redundante, já é implícito) — `422` se incluir.
  Guardado por `can:users.manage` (permission já existente).

Nenhuma permission nova é criada.

## 5. Migrations e seeds

Duas migrations novas (`create_grupo_solucao_permissoes_table`,
`create_user_grupo_solucao_visibilidade_table`). Sem alteração em
`RolesAndPermissionsSeeder` nem `DatabaseSeeder` — grupos existentes
(`Administração`, `Suporte N1`, `Suporte N2`) continuam sem nenhuma
linha nas tabelas novas até um admin configurar algo, preservando o
comportamento atual para todo mundo até então.

## 6. Plano de UI (SPA Vue — repositório separado, fora deste escopo de implementação)

- **Tela "Grupos de Solução" → aba "Acessos"** (por grupo): tabela de
  todas as permissions agrupadas pela mesma área que os slugs já usam
  (tickets, clients, users, customers, slas, categorias, grupos_solucao,
  relatorios), cada linha com um seletor de 3 estados — **Padrão**
  (herda do Role, sem override) / **Liberada** / **Bloqueada**. Salvar
  chama o `PUT /grupos-solucao/{grupo}/permissoes`.
- **Tela de edição de Usuário → seção "Visibilidade extra de
  incidentes"**: multi-select dos grupos de solução (excluindo o
  grupo primário do usuário, que já aparece implícito/desabilitado na
  lista). Salvar chama `PUT /users/{user}/grupos-visiveis`.

## Testes

- `hasPermission()`: Role concede + grupo sem override → `true`
  (comportamento atual preservado). Role não concede + grupo libera →
  `true`. Role concede + grupo bloqueia → `false`. Qualquer combinação
  acima com usuário `admin` → sempre segue só o Role (bypass).
- `Incidente::scopeVisiveisPara()`: usuário vê só incidentes do
  próprio grupo + extras concedidos + os sem grupo; `admin` vê todos.
- `IncidentePolicy`: `403` ao tentar `show`/`update` um incidente fora
  do escopo de visibilidade; `200` para um dentro do escopo ou sem
  grupo definido.
- Endpoints de administração: `422` em payload com listas não
  disjuntas / grupo próprio incluído; `403` sem a permission de
  `manage` correspondente; efeito persistido corretamente após `PUT`.
