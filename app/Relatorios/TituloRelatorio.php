<?php

namespace App\Relatorios;

use App\Models\Incidente;
use Carbon\Carbon;

/**
 * Frase que descreve a consulta (spec 5.4). Montada no backend e não na UI
 * para que o JSON, a planilha e o relatório salvo digam exatamente a mesma
 * coisa — quem recebe só a planilha precisa saber o que ela mede.
 */
final class TituloRelatorio
{
    private const SUJEITOS = [
        'abertos' => 'Chamados abertos (criados)',
        'concluidos' => 'Chamados concluídos (resolvidos ou fechados)',
        'resolvidos' => 'Chamados resolvidos',
        'fechados' => 'Chamados fechados',
        'cancelados' => 'Chamados cancelados',
        'encaminhamentos' => 'Encaminhamentos',
    ];

    /** Nome do agrupamento "por ..."; a planilha o capitaliza no cabeçalho da 1ª coluna. */
    public const AGRUPAMENTOS = [
        'analista' => 'analista',
        'grupo_analista' => 'grupo do analista',
        'grupo_chamado' => 'grupo de solução',
        'categoria' => 'categoria',
        'subcategoria' => 'subcategoria',
        'item' => 'item',
        'cliente' => 'cliente',
        'tipo' => 'tipo',
        'status_sla' => 'status de SLA',
        'responsavel' => 'responsável',
        'aberto_por' => 'quem abriu',
        'quem_encaminhou' => 'quem encaminhou',
        'para_grupo' => 'grupo de destino',
        'para_responsavel' => 'responsável de destino',
    ];

    private const QUEBRAS = ['dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês'];

    public const STATUS = [
        'aberto' => 'Aberto',
        'em_andamento' => 'Em andamento',
        'pendente' => 'Pendente',
        'aguardando_interno' => 'Aguardando Interno',
        'aguardando_externo' => 'Aguardando Externo',
        'resolvido' => 'Resolvido',
        'fechado' => 'Fechado',
        'cancelado' => 'Cancelado',
    ];

    public static function montar(ConsultaRelatorio $c): string
    {
        $periodo = $c->temPeriodo()
            ? __('de :inicio a :fim', ['inicio' => self::data($c->inicio), 'fim' => self::data($c->fim)])
            : __('em todo o período');

        $titulo = __(':sujeito :periodo por :agrupamento', [
            'sujeito' => __(self::SUJEITOS[$c->contar]),
            'periodo' => $periodo,
            'agrupamento' => self::agrupamento($c->agruparPor),
        ]);

        if (isset(self::QUEBRAS[$c->quebra])) {
            $titulo .= __(', por :quebra', ['quebra' => __(self::QUEBRAS[$c->quebra])]);
        }

        $filtros = self::filtros($c->filtros);

        return $filtros === [] ? $titulo : $titulo.' — '.implode('; ', $filtros);
    }

    /** Nome do agrupamento "por ...", no idioma da requisição. */
    public static function agrupamento(string $agruparPor): string
    {
        return __(self::AGRUPAMENTOS[$agruparPor]);
    }

    /** Rótulo de um status do chamado, no idioma da requisição. */
    public static function status(string $status): string
    {
        return isset(self::STATUS[$status]) ? __(self::STATUS[$status]) : $status;
    }

    /**
     * Um filtro por vez, na ordem fixa do spec; ids viram nomes com uma
     * consulta cada (no máximo dez filtros por pedido).
     *
     * @return array<int, string>
     */
    private static function filtros(array $f): array
    {
        $partes = [];
        $nome = function (string $chave, string $rotulo, callable $resolver) use (&$partes, $f): void {
            if (isset($f[$chave])) {
                $partes[] = "{$rotulo}: ".($resolver([$f[$chave]])[$f[$chave]] ?? "#{$f[$chave]}");
            }
        };

        $nome('grupo_analista_id', __('grupo do analista'), RotulosRelatorio::grupos(...));
        $nome('grupo_solucao_id', __('grupo de solução'), RotulosRelatorio::grupos(...));
        $nome('client_id', __('cliente'), RotulosRelatorio::clientes(...));
        // `customer_id` é o usuário do portal, não a empresa (spec 6A.8).
        $nome('customer_id', __('usuário do cliente'), RotulosRelatorio::customers(...));
        $nome('categoria_id', __('categoria'), RotulosRelatorio::categorias(...));
        $nome('subcategoria_id', __('subcategoria'), RotulosRelatorio::subcategorias(...));
        $nome('item_id', __('item'), RotulosRelatorio::itens(...));

        if (isset($f['tipo'])) {
            $partes[] = __('tipo').': '.(isset(Incidente::TIPO_ROTULOS[$f['tipo']]) ? __(Incidente::TIPO_ROTULOS[$f['tipo']]) : $f['tipo']);
        }

        $nome('responsavel_id', __('responsável'), RotulosRelatorio::usuarios(...));

        if (isset($f['status'])) {
            $partes[] = __('status').': '.self::status($f['status']);
        }

        return $partes;
    }

    private static function data(string $ymd): string
    {
        return Carbon::createFromFormat('Y-m-d', $ymd)->format(__('d/m/Y'));
    }
}
