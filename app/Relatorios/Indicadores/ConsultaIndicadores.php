<?php

namespace App\Relatorios\Indicadores;

use App\Models\User;
use App\Relatorios\ConsultaRelatorio;
use App\Support\PeriodoRelatorio;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pedido do relatório "Indicadores por grupo", já validado e com o atalho de
 * período resolvido. Mesmo objeto para a requisição direta e para o relatório
 * salvo, por isso as regras ficam aqui e não no controller.
 */
final readonly class ConsultaIndicadores
{
    /**
     * @param  array<int, int>  $grupos  ids na ordem pedida; vazio = todos os visíveis
     * @param  string  $inicio  Y-m-d (dia de Brasília, inclusivo), já resolvido do atalho
     * @param  string  $fim  Y-m-d (dia de Brasília, inclusivo), já resolvido do atalho
     * @param  string|null  $atalho  atalho original (`este_mes`...), guardado para o relatório salvo
     */
    public function __construct(
        public array $grupos,
        public bool $porIntegrante,
        public string $quebra,
        public string $inicio,
        public string $fim,
        public ?string $atalho,
    ) {}

    /**
     * Período e quebra com as mesmas regras (e mensagens) do caminho `contar`,
     * mais o período obrigatório e a seleção de grupos.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function regras(): array
    {
        $base = ConsultaRelatorio::regras();

        return [
            'grupos' => ['sometimes', 'array'],
            'grupos.*' => ['integer', 'distinct', 'exists:grupos_solucao,id'],
            'por_integrante' => ['sometimes', 'boolean', self::regraUmGrupo()],
            'quebra' => ['sometimes', 'string', Rule::in(PeriodoRelatorio::QUEBRAS), ConsultaRelatorio::regraQuebra()],
            // Sem `sometimes`: a regra de período obrigatório precisa rodar com o campo ausente.
            'periodo' => [self::regraPeriodoObrigatorio(), ...array_values(array_filter($base['periodo'], fn ($r) => $r !== 'sometimes'))],
            'data_inicio' => $base['data_inicio'],
            'data_fim' => $base['data_fim'],
        ];
    }

    /** Monta a consulta a partir do array validado por `regras()`; o atalho é resolvido na execução. */
    public static function deDados(array $validado, ?CarbonInterface $agora = null): self
    {
        $atalho = $validado['periodo'] ?? null;
        [$inicio, $fim] = $atalho !== null
            ? PeriodoRelatorio::resolverAtalho($atalho, $agora)
            : [$validado['data_inicio'], $validado['data_fim']];

        return new self(
            grupos: array_map('intval', array_values($validado['grupos'] ?? [])),
            porIntegrante: filter_var($validado['por_integrante'] ?? false, FILTER_VALIDATE_BOOLEAN),
            quebra: $validado['quebra'] ?? 'nenhuma',
            inicio: $inicio,
            fim: $fim,
            atalho: $atalho,
        );
    }

    /**
     * Um grupo fora dos visíveis não pode ser pedido: o número dele revelaria
     * a produção de um time que a pessoa não enxerga. Admin passa direto.
     *
     * @throws ValidationException
     */
    public function validarVisibilidade(User $u): void
    {
        $visiveis = $u->visibleGrupoSolucaoIds();

        if ($visiveis === null) {
            return;
        }

        $visiveis = array_map('intval', array_filter($visiveis, fn ($id) => $id !== null));
        $erros = [];
        foreach ($this->grupos as $i => $grupo) {
            if (! in_array($grupo, $visiveis, true)) {
                $erros["grupos.{$i}"] = 'Grupo fora dos seus grupos visíveis.';
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }

    /** Implícita (roda com `periodo` ausente): sem atalho nem datas, não há período. */
    private static function regraPeriodoObrigatorio(): ValidationRule
    {
        return self::regraComDados(function (mixed $valor, array $dados, Closure $falhar): void {
            $vazio = fn (string $campo) => ($dados[$campo] ?? null) === null || $dados[$campo] === '';

            if ($vazio('periodo') && $vazio('data_inicio') && $vazio('data_fim')) {
                $falhar('Informe um período.');
            }
        }, implicita: true);
    }

    private static function regraUmGrupo(): ValidationRule
    {
        return self::regraComDados(function (mixed $valor, array $dados, Closure $falhar): void {
            $grupos = $dados['grupos'] ?? [];

            if (filter_var($valor, FILTER_VALIDATE_BOOLEAN) && (! is_array($grupos) || count($grupos) !== 1)) {
                $falhar('Selecione um único grupo para detalhar por integrante.');
            }
        });
    }

    /**
     * Regra que enxerga os demais campos do conjunto validado (`DataAwareRule`).
     * `$implicit` faz o validador rodá-la mesmo com o campo ausente.
     *
     * @param  Closure(mixed, array, Closure): void  $verificar
     */
    private static function regraComDados(Closure $verificar, bool $implicita = false): ValidationRule
    {
        return new class($verificar, $implicita) implements DataAwareRule, ValidationRule
        {
            private array $dados = [];

            public function __construct(private Closure $verificar, public bool $implicit) {}

            public function setData(array $data): static
            {
                $this->dados = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                ($this->verificar)($value, $this->dados, $fail);
            }
        };
    }
}
