<?php

namespace App\Relatorios\Fluxos;

use App\Relatorios\LinhaBase;

/**
 * Um evento que levou um chamado de uma origem a um grupo de destino
 * diferente: um `encaminhado_grupo` ou um `aberto` direto para outro grupo.
 * `origem`/`destino` são chaves de nó (id do grupo como string, `cliente`,
 * `sem_grupo`, `origem_desconhecida` ou `outros`), já com a visibilidade
 * aplicada; `base->chave` é `"{origem}>{destino}"`.
 */
final readonly class Passagem
{
    public const VIA_ENCAMINHAMENTO = 'encaminhamento';

    public const VIA_ABERTURA = 'abertura';

    public function __construct(
        public string $origem,
        public string $destino,
        public string $via,
        public LinhaBase $base,
    ) {}
}
