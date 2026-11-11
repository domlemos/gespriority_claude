<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Consulta do log de ações (Administração > Logs de ações). Nada é listado
 * sem filtro: o período é obrigatório e limitado a MAX_DIAS, e a paginação é
 * simples (sem COUNT(*) numa tabela que só cresce). Ver BACKEND_SPECS.md §3.4.11.
 */
class AuditLogController extends Controller
{
    public const MAX_DIAS = 93;

    public const POR_PAGINA = 50;

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'data_inicio' => ['required', 'date_format:Y-m-d'],
            'data_fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'ator_type' => ['sometimes', 'nullable', Rule::in(['user', 'customer'])],
            'acao' => ['sometimes', 'nullable', 'array'],
            'acao.*' => ['string', Rule::in(array_keys(AuditLog::ACOES))],
            'entidade_type' => ['sometimes', 'nullable', Rule::in(array_keys(AuditLog::ENTIDADES))],
            'entidade_id' => ['sometimes', 'nullable', 'integer'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:45'],
            'busca' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if (Carbon::parse($filtros['data_inicio'])->diffInDays(Carbon::parse($filtros['data_fim'])) >= self::MAX_DIAS) {
            throw ValidationException::withMessages([
                'data_fim' => 'O período máximo da consulta é de '.self::MAX_DIAS.' dias.',
            ]);
        }

        $pagina = AuditLog::query()
            ->filtros($filtros)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(self::POR_PAGINA);

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at,
                'acao' => $log->acao,
                'acao_rotulo' => AuditLog::ACOES[$log->acao] ?? $log->acao,
                'ator' => $log->ator_type || $log->ator_nome ? [
                    'type' => $log->ator_type,
                    'id' => $log->ator_id,
                    'nome' => $log->ator_nome,
                ] : null,
                'entidade' => $log->entidade_type ? [
                    'type' => $log->entidade_type,
                    'type_rotulo' => AuditLog::ENTIDADES[$log->entidade_type][1] ?? $log->entidade_type,
                    'id' => $log->entidade_id,
                    'label' => $log->entidade_label,
                ] : null,
                'alteracoes' => $log->alteracoes,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
            ])->values(),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'has_more' => $pagina->hasMorePages(),
            ],
        ]);
    }

    /** Opções dos selects da tela — sem tocar na tabela de logs. */
    public function opcoes(): JsonResponse
    {
        return response()->json([
            'acoes' => collect(AuditLog::ACOES)->map(fn ($rotulo, $slug) => ['value' => $slug, 'title' => $rotulo])->values(),
            'entidades' => collect(AuditLog::ENTIDADES)->map(fn ($e, $slug) => ['value' => $slug, 'title' => $e[1]])->values(),
            'max_dias' => self::MAX_DIAS,
            'retencao_meses' => AuditLog::RETENCAO_MESES,
        ]);
    }
}
