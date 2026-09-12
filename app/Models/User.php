<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// "Excluir" um usuário é desativação (soft delete), não remoção de verdade —
// login passa a falhar (query de credenciais some por trás do global scope
// padrão do SoftDeletes) e o usuário some da listagem, mas referências
// históricas (Incidente.responsavel, IncidenteDescricao.user, Anexo.user)
// continuam resolvendo o nome via withTrashed() nessas relações. Ver
// BACKEND_SPECS.md §3.4.3.
#[Fillable(['name', 'email', 'password', 'grupo_solucao_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function grupoSolucao(): BelongsTo
    {
        return $this->belongsTo(GrupoSolucao::class);
    }

    public function gruposVisiveisExtra(): BelongsToMany
    {
        return $this->belongsToMany(GrupoSolucao::class, 'user_grupo_solucao_visibilidade');
    }

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

    public function incidentesResponsavel(): HasMany
    {
        return $this->hasMany(Incidente::class, 'responsavel_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(Anexo::class);
    }

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

    /**
     * Filtro composto pra listagem — cada chave é opcional (só filtra se
     * presente em `$filtros`), combinadas com AND. `name`/`email` são
     * `LIKE` parcial; `grupo_solucao_id` é igualdade exata; `role_id` filtra
     * usuários que possuem aquele papel via `whereHas`. Validação dos
     * valores (`exists`) é responsabilidade de quem chama
     * (`UserController::index()`), não deste scope — ele só monta a query.
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['name'] ?? null, fn (Builder $q, string $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($filtros['email'] ?? null, fn (Builder $q, string $v) => $q->where('email', 'like', "%{$v}%"))
            ->when($filtros['grupo_solucao_id'] ?? null, fn (Builder $q, int $v) => $q->where('grupo_solucao_id', $v))
            ->when(
                $filtros['role_id'] ?? null,
                fn (Builder $q, int $v) => $q->whereHas('roles', fn (Builder $r) => $r->where('roles.id', $v)),
            );
    }
}
