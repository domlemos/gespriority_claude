<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['client_id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function incidentes(): HasMany
    {
        return $this->hasMany(Incidente::class);
    }

    /**
     * Filtro composto pra listagem — cada chave é opcional (só filtra se
     * presente em `$filtros`), combinadas com AND. `name`/`email` são
     * `LIKE` parcial; `client_id` é igualdade exata. Validação dos valores
     * (`exists`) é responsabilidade de quem chama
     * (`CustomerController::index()`), não deste scope — ele só monta a
     * query.
     */
    public function scopeFiltros(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['name'] ?? null, fn (Builder $q, string $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($filtros['email'] ?? null, fn (Builder $q, string $v) => $q->where('email', 'like', "%{$v}%"))
            ->when($filtros['client_id'] ?? null, fn (Builder $q, int $v) => $q->where('client_id', $v));
    }
}
