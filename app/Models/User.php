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

    public function incidentesResponsavel(): HasMany
    {
        return $this->hasMany(Incidente::class, 'responsavel_id');
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(Anexo::class);
    }

    public function hasPermission(string $slug): bool
    {
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->pluck('permissions')
            ->flatten()
            ->pluck('slug')
            ->contains($slug);
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
