<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoriasSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Taxonomia de categorias/subcategorias/itens de incidentes (3 níveis:
     * Categoria > Subcategoria > Item).
     */
    private const TAXONOMIA = [
        'Dossie' => [
            'Detalhado' => ['Divergência', 'Falha'],
            'Analítico' => ['Divergência', 'Falha'],
            'Workflow' => ['Critérios', 'Aprovação'],
            'Upscore' => ['Cálculo', 'Erro'],
            'Fonte' => ['Não processa', 'Erro'],
        ],
        'Veiculo' => [
            'Gravame' => ['Chassi', 'Erro'],
            'Chassi' => ['Erro'],
        ],
        'Upminer' => [
            'Upacademy' => ['Erro', 'Vídeo não carrega'],
            'SSO' => [],
        ],
        'Uplink' => [
            'QSA' => ['Erro', 'Lentidão'],
            'Veiculo' => [],
        ],
        'Fonte' => [
            'Monitoramento' => ['Captura', 'Preventivo', 'Corretivo'],
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
