<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoriasSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Taxonomia padrão de categorias/subcategorias/itens de incidentes —
     * massa inicial comum de ITSM (3 níveis: Categoria > Subcategoria >
     * Item), não é exaustiva, só o suficiente pra exercitar o cadastro
     * (mais entram depois via API mesmo).
     */
    private const TAXONOMIA = [
        'Hardware' => [
            'Computador' => ['Não liga', 'Tela azul'],
            'Impressora' => ['Sem toner', 'Atolamento de papel'],
            'Periféricos' => ['Mouse não funciona', 'Teclado não funciona'],
        ],
        'Software' => [
            'Sistema Operacional' => ['Lentidão', 'Erro de atualização'],
            'Aplicativo' => ['Não abre', 'Trava/congela'],
            'Licença' => ['Expirada', 'Não ativa'],
        ],
        'Rede' => [
            'Internet' => ['Sem conexão', 'Lentidão'],
            'VPN' => ['Não conecta', 'Queda de conexão'],
            'Wi-Fi' => ['Sinal fraco', 'Não conecta'],
        ],
        'Acesso' => [
            'Senha' => ['Esqueci a senha', 'Conta bloqueada por tentativas'],
            'Permissão' => ['Acesso negado', 'Solicitação de novo acesso'],
            'Conta Bloqueada' => ['Desbloqueio de conta', 'Conta suspensa'],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::TAXONOMIA as $categoriaNome => $subcategorias) {
            $categoria = Categoria::query()->updateOrCreate(
                ['nome' => $categoriaNome],
                ['ativo' => true]
            );

            foreach ($subcategorias as $subcategoriaNome => $itens) {
                $subcategoria = $categoria->subcategorias()->updateOrCreate(
                    ['nome' => $subcategoriaNome],
                    ['ativo' => true]
                );

                foreach ($itens as $itemNome) {
                    $subcategoria->itens()->updateOrCreate(
                        ['nome' => $itemNome],
                        ['ativo' => true]
                    );
                }
            }
        }
    }
}
