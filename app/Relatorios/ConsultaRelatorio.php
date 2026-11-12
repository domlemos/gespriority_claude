<?php

namespace App\Relatorios;

use App\Models\Incidente;
use App\Support\PeriodoRelatorio;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Pedido do caminho novo de relatórios (`contar`), já validado e com o atalho
 * de período resolvido em datas. É o mesmo objeto para a requisição direta e
 * para o relatório salvo (Task 6), por isso as regras ficam aqui e não no
 * controller.
 */
final readonly class ConsultaRelatorio
{
    /** O que contar — ver tabela em BACKEND_SPECS §3.4.9 ("Caminho novo"). */
    public const CONTAR = ['abertos', 'concluidos', 'resolvidos', 'fechados', 'cancelados', 'encaminhamentos'];

    private const AGRUPAMENTOS_INCIDENTE = ['categoria', 'subcategoria', 'item', 'grupo_chamado', 'responsavel', 'aberto_por', 'cliente', 'tipo'];

    private const AGRUPAMENTOS_CONCLUSAO = ['analista', 'grupo_analista', 'grupo_chamado', 'categoria', 'subcategoria', 'item', 'cliente', 'tipo', 'status_sla'];

    /**
     * Agrupamentos oferecidos por `contar` (spec 5.2): cada base só sabe
     * agrupar pelo que tem — um chamado aberto não tem "analista que concluiu",
     * um encaminhamento não tem categoria própria.
     */
    public const AGRUPAMENTOS_POR_CONTAR = [
        'abertos' => self::AGRUPAMENTOS_INCIDENTE,
        'cancelados' => self::AGRUPAMENTOS_INCIDENTE,
        'concluidos' => self::AGRUPAMENTOS_CONCLUSAO,
        'resolvidos' => self::AGRUPAMENTOS_CONCLUSAO,
        'fechados' => self::AGRUPAMENTOS_CONCLUSAO,
        'encaminhamentos' => ['quem_encaminhou', 'para_grupo', 'para_responsavel'],
    ];

    /** Filtros de recorte (estado do chamado ou snapshot do evento, conforme a fonte). */
    public const FILTROS = [
        'status', 'tipo', 'categoria_id', 'subcategoria_id', 'item_id', 'grupo_solucao_id',
        'grupo_analista_id', 'responsavel_id', 'client_id', 'customer_id',
    ];

    private const NOME_QUEBRA = ['dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês'];

    /**
     * @param  string|null  $inicio  Y-m-d (dia de Brasília, inclusivo), já resolvido do atalho
     * @param  string|null  $fim  Y-m-d (dia de Brasília, inclusivo), já resolvido do atalho
     * @param  string|null  $atalho  atalho original (`este_mes`...), guardado para o relatório salvo e o título
     * @param  array<string, mixed>  $filtros  só as chaves de `FILTROS` presentes
     */
    public function __construct(
        public string $contar,
        public string $agruparPor,
        public string $quebra,
        public ?string $inicio,
        public ?string $fim,
        public ?string $atalho,
        public array $filtros,
    ) {}

    /**
     * Regras de validação, com chaves sem prefixo para o relatório salvo
     * poder validar o mesmo conjunto dentro de `filtros`. As regras cruzadas
     * (agrupamento × contar, quebra × período) leem os dados irmãos via
     * `DataAwareRule`, então funcionam em qualquer validador que receba o
     * conjunto inteiro.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function regras(): array
    {
        return [
            'contar' => ['required', 'string', Rule::in(self::CONTAR)],
            'agrupar_por' => ['required', 'string', self::regraCruzada(self::validarAgrupamento(...))],
            'quebra' => ['sometimes', 'string', Rule::in(PeriodoRelatorio::QUEBRAS), self::regraQuebra()],
            'periodo' => ['sometimes', 'string', Rule::in(PeriodoRelatorio::ATALHOS), 'prohibits:data_inicio,data_fim'],
            // Período "de X a Y" só faz sentido com as duas pontas.
            'data_inicio' => ['required_with:data_fim', 'date_format:Y-m-d'],
            'data_fim' => ['required_with:data_inicio', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
            'status' => ['sometimes', 'string', Rule::in(Incidente::STATUSES)],
            'tipo' => ['sometimes', 'string', Rule::in(Incidente::TIPOS)],
            'categoria_id' => ['sometimes', 'integer', 'exists:categorias,id'],
            'subcategoria_id' => ['sometimes', 'integer', 'exists:subcategorias,id'],
            'item_id' => ['sometimes', 'integer', 'exists:itens,id'],
            'grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'grupo_analista_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'responsavel_id' => ['sometimes', 'integer', 'exists:users,id'],
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
        ];
    }

    /**
     * Monta a consulta a partir do array validado por `regras()`. O atalho é
     * resolvido aqui, no momento da execução — um relatório salvo como
     * "este mês" precisa andar com o calendário.
     */
    public static function deDados(array $validado, ?CarbonInterface $agora = null): self
    {
        $atalho = $validado['periodo'] ?? null;
        [$inicio, $fim] = $atalho !== null
            ? PeriodoRelatorio::resolverAtalho($atalho, $agora)
            : [$validado['data_inicio'] ?? null, $validado['data_fim'] ?? null];

        $filtros = array_intersect_key($validado, array_flip(self::FILTROS));
        foreach ($filtros as $chave => $valor) {
            if (str_ends_with($chave, '_id')) {
                $filtros[$chave] = (int) $valor;
            }
        }

        return new self(
            contar: $validado['contar'],
            agruparPor: $validado['agrupar_por'],
            quebra: $validado['quebra'] ?? 'nenhuma',
            inicio: $inicio,
            fim: $fim,
            atalho: $atalho,
            filtros: $filtros,
        );
    }

    public function temPeriodo(): bool
    {
        return $this->inicio !== null && $this->fim !== null;
    }

    public function fonte(): FonteRelatorio
    {
        return match ($this->contar) {
            'abertos', 'cancelados' => new FonteIncidentes,
            default => new FonteEventos($this->contar === 'encaminhamentos'),
        };
    }

    /**
     * Regra cruzada quebra × período (exige período e respeita
     * `PeriodoRelatorio::LIMITE_COLUNAS`). Pública para outras consultas de
     * relatório (`ConsultaIndicadores`) aplicarem exatamente a mesma regra.
     */
    public static function regraQuebra(): ValidationRule
    {
        return self::regraCruzada(self::validarQuebra(...));
    }

    private static function validarAgrupamento(string $valor, array $dados, Closure $falhar): void
    {
        $permitidos = self::AGRUPAMENTOS_POR_CONTAR[$dados['contar'] ?? ''] ?? null;

        // `contar` inválido já tem o próprio erro; não duplica aqui.
        if ($permitidos !== null && ! in_array($valor, $permitidos, true)) {
            $falhar("Agrupamento \"{$valor}\" não está disponível para contar={$dados['contar']}.");
        }
    }

    private static function validarQuebra(string $valor, array $dados, Closure $falhar): void
    {
        if (! isset(self::NOME_QUEBRA[$valor])) {
            return;
        }

        $nome = self::NOME_QUEBRA[$valor];
        $periodo = self::periodoDosDados($dados);

        if ($periodo === null) {
            $falhar("Informe um período para quebrar por {$nome}.");

            return;
        }

        [$inicio, $fim] = $periodo;
        if ($fim < $inicio) {
            return; // `data_fim` já acusa o erro.
        }

        $limite = PeriodoRelatorio::LIMITE_COLUNAS[$valor];
        if (self::excedeLimite($inicio, $fim, $valor, $limite)) {
            $falhar("Período longo demais para quebra por {$nome}: máximo de {$limite} colunas.");
        }
    }

    /**
     * Pré-checagem barata por dias antes de gerar as colunas: `colunas()` itera
     * balde a balde, e um período de séculos não pode virar milhões de voltas.
     */
    private static function excedeLimite(string $inicio, string $fim, string $quebra, int $limite): bool
    {
        $dias = Carbon::parse($inicio)->diffInDays(Carbon::parse($fim)) + 1;
        $diasPorColuna = ['dia' => 1, 'semana' => 7, 'mes' => 31][$quebra];

        if ($dias > ($limite + 1) * $diasPorColuna) {
            return true;
        }

        return count(PeriodoRelatorio::colunas($inicio, $fim, $quebra)) > $limite;
    }

    /** @return array{0: string, 1: string}|null */
    private static function periodoDosDados(array $dados): ?array
    {
        if (isset($dados['periodo'])) {
            return in_array($dados['periodo'], PeriodoRelatorio::ATALHOS, true)
                ? PeriodoRelatorio::resolverAtalho($dados['periodo'])
                : null;
        }

        $inicio = $dados['data_inicio'] ?? null;
        $fim = $dados['data_fim'] ?? null;

        if (! self::dataValida($inicio) || ! self::dataValida($fim)) {
            return null;
        }

        return [$inicio, $fim];
    }

    private static function dataValida(mixed $data): bool
    {
        return is_string($data) && Carbon::canBeCreatedFromFormat($data, 'Y-m-d');
    }

    /**
     * Regra que enxerga os demais campos do conjunto validado. Closures puras
     * não recebem os dados irmãos; `DataAwareRule` recebe.
     *
     * @param  Closure(string, array, Closure): void  $verificar
     */
    private static function regraCruzada(Closure $verificar): ValidationRule
    {
        return new class($verificar) implements DataAwareRule, ValidationRule
        {
            private array $dados = [];

            public function __construct(private Closure $verificar) {}

            public function setData(array $data): static
            {
                $this->dados = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (is_string($value)) {
                    ($this->verificar)($value, $this->dados, $fail);
                }
            }
        };
    }
}
