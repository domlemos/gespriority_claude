<?php

namespace Tests\Feature\I18n;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TraducoesCobertasTest extends TestCase
{
    /**
     * Constantes de rótulo (arrays) cujos VALORES também precisam existir em
     * lang/en.json. Para cobrir uma nova constante, acrescente uma linha.
     *
     * @return array<string, array<int|string, string>>
     */
    private static function constantesDeRotulo(): array
    {
        return [
            // 'Classe::CONSTANTE' => Classe::CONSTANTE,
        ];
    }

    /** @return array<string, string> */
    private function en(): array
    {
        return json_decode(File::get(lang_path('en.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Literais passados a __(), trans() e @lang() em app/ e nos e-mails.
     * Ignora chaves de arquivo PHP (com ponto e sem espaço, ex.: validation.required).
     *
     * @return array<string, string> literal => arquivo onde aparece
     */
    private function literais(): array
    {
        $arquivos = [];
        foreach (File::allFiles(app_path()) as $f) {
            if ($f->getExtension() === 'php') {
                $arquivos[] = $f->getPathname();
            }
        }
        $emails = resource_path('views/emails');
        if (File::isDirectory($emails)) {
            foreach (File::allFiles($emails) as $f) {
                if (str_ends_with($f->getFilename(), '.blade.php')) {
                    $arquivos[] = $f->getPathname();
                }
            }
        }

        $padrao = '/(?:\b__|\btrans|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s';
        $achados = [];
        foreach ($arquivos as $arquivo) {
            if (! preg_match_all($padrao, File::get($arquivo), $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $hit) {
                $literal = ($hit[1] ?? '') !== '' ? stripcslashes($hit[1]) : stripcslashes($hit[2] ?? '');
                if ($literal === '' || (str_contains($literal, '.') && ! str_contains($literal, ' '))) {
                    continue;
                }
                $achados[$literal] = $arquivo;
            }
        }

        return $achados;
    }

    public function test_todo_literal_traduzido_existe_em_en_json(): void
    {
        $en = $this->en();
        $faltando = array_diff_key($this->literais(), $en);

        $this->assertSame([], array_keys($faltando), 'Faltam em lang/en.json: '.json_encode(array_keys($faltando), JSON_UNESCAPED_UNICODE));
    }

    public function test_valores_das_constantes_de_rotulo_existem_em_en_json(): void
    {
        $en = $this->en();
        $faltando = [];
        foreach (self::constantesDeRotulo() as $nome => $valores) {
            foreach ($valores as $valor) {
                if (! array_key_exists($valor, $en)) {
                    $faltando[] = "$nome: $valor";
                }
            }
        }

        $this->assertSame([], $faltando, 'Faltam em lang/en.json: '.json_encode($faltando, JSON_UNESCAPED_UNICODE));
    }

    public function test_pt_br_json_nao_traduz_chaves_usadas_em_app(): void
    {
        $arquivo = lang_path('pt_BR.json');
        if (! File::exists($arquivo)) {
            $this->markTestSkipped('lang/pt_BR.json não existe.');
        }

        $pt = json_decode(File::get($arquivo), true, 512, JSON_THROW_ON_ERROR);
        $usadas = array_intersect_key($this->literais(), $pt);

        $this->assertSame([], array_keys($usadas), 'Chaves de lang/pt_BR.json usadas como literal em app/ (a frase PT é a própria chave).');
    }
}
