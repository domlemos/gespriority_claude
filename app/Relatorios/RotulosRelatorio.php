<?php

namespace App\Relatorios;

use App\Models\Categoria;
use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Subcategoria;
use App\Models\User;

/**
 * Chave → nome legível das linhas, compartilhado por todas as fontes (e pelo
 * título/planilha): uma consulta só por agrupamento, com os ids que de fato
 * apareceram — nunca N+1.
 */
final class RotulosRelatorio
{
    /** Chave de resposta para "sem valor" (coluna nula): o JSON não tem chave nula. */
    public const CHAVE_VAZIA = '__vazio__';

    /** Linha separada de "aberto por" para chamados abertos pelo portal (Fase 1). */
    public const CHAVE_PORTAL = 'portal';

    /** Rótulo da linha sem valor, por agrupamento. */
    public const VAZIOS = [
        'categoria' => '(sem classificação)',
        'subcategoria' => '(sem classificação)',
        'item' => '(sem classificação)',
        'grupo_chamado' => '(sem grupo)',
        'grupo_analista' => '(sem grupo)',
        'para_grupo' => '(sem grupo)',
        'responsavel' => '(sem responsável)',
        'para_responsavel' => '(sem responsável)',
        'analista' => '(usuário desconhecido)',
        'quem_encaminhou' => '(usuário desconhecido)',
        'aberto_por' => '(desconhecido)',
        'cliente' => '(sem cliente)',
        'tipo' => '(sem tipo)',
        'status_sla' => '(sem SLA)',
    ];

    /** Mesmos rótulos de SLA da Fase 1 (`RelatorioController::agruparPorStatusSla`). */
    public const STATUS_SLA = [
        Incidente::SLA_DENTRO_PRAZO => 'Dentro do prazo',
        Incidente::SLA_ESTOURADO => 'Fora do prazo',
        Incidente::SLA_SEM_SLA => 'Sem SLA aplicável',
    ];

    /**
     * Rótulo de cada chave de um agrupamento. Id que não existe mais vira
     * `#id` (melhor que esconder a linha e o número não fechar).
     *
     * @param  array<int, int|string>  $chaves
     * @return array<int|string, string>
     */
    public static function resolver(string $agruparPor, array $chaves): array
    {
        $ids = array_values(array_filter(
            $chaves,
            fn ($c) => $c !== self::CHAVE_VAZIA && $c !== self::CHAVE_PORTAL
        ));

        $nomes = match ($agruparPor) {
            'categoria' => self::categorias($ids),
            'subcategoria' => self::subcategorias($ids),
            'item' => self::itens($ids),
            'grupo_chamado', 'grupo_analista', 'para_grupo' => self::grupos($ids),
            'responsavel', 'para_responsavel', 'analista', 'quem_encaminhou', 'aberto_por' => self::usuarios($ids),
            'cliente' => self::clientes($ids),
            'tipo' => Incidente::TIPO_ROTULOS,
            'status_sla' => self::STATUS_SLA,
        };

        $rotulos = [];
        foreach ($chaves as $chave) {
            $rotulos[$chave] = match (true) {
                $chave === self::CHAVE_VAZIA => self::VAZIOS[$agruparPor],
                $chave === self::CHAVE_PORTAL && $agruparPor === 'aberto_por' => 'Portal (cliente)',
                default => $nomes[$chave] ?? "#{$chave}",
            };
        }

        return $rotulos;
    }

    /** Usuários desativados continuam com nome: o histórico não pode virar "#12". */
    public static function usuarios(array $ids): array
    {
        return $ids === [] ? [] : User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    public static function grupos(array $ids): array
    {
        return $ids === [] ? [] : GrupoSolucao::whereIn('id', $ids)->pluck('nome', 'id')->all();
    }

    public static function categorias(array $ids): array
    {
        return $ids === [] ? [] : Categoria::whereIn('id', $ids)->pluck('nome', 'id')->all();
    }

    public static function subcategorias(array $ids): array
    {
        return $ids === [] ? [] : Subcategoria::whereIn('id', $ids)->pluck('nome', 'id')->all();
    }

    public static function itens(array $ids): array
    {
        return $ids === [] ? [] : Item::whereIn('id', $ids)->pluck('nome', 'id')->all();
    }

    /** Empresa (`clients`), não o usuário do portal (`customers`) — spec 6A.8. */
    public static function clientes(array $ids): array
    {
        return $ids === [] ? [] : Client::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** Usuário do portal (`customers`) — o filtro `customer_id`, distinto de `clientes()`. */
    public static function customers(array $ids): array
    {
        return $ids === [] ? [] : Customer::whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
