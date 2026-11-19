<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\PoliticaSla;
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
        // Índice corrido por TODO o catálogo (não reiniciado a cada
        // subcategoria) — com só 2 itens na maioria das subcategorias, um
        // índice reiniciado nunca passaria de baixa/media, e nenhum item de
        // demonstração teria alta/urgente nem `null` (item sem SLA padrão,
        // que precisa continuar existindo pra exercitar o fluxo antigo de
        // prioridade livre — ver "SLA por Categorização" no BACKEND_SPECS.md
        // §3.1). `null` primeiro no ciclo pra alternar com as 4 prioridades.
        $prioridadesPadraoCiclo = [null, ...PoliticaSla::PRIORIDADES];
        $indice = 0;

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
                        [
                            'ativo' => true,
                            'prioridade_padrao' => $prioridadesPadraoCiclo[$indice % count($prioridadesPadraoCiclo)],
                        ]
                    );

                    $indice++;
                }
            }
        }
    }
}
