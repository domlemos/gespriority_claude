<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnexoResource;
use App\Models\Anexo;
use App\Models\Incidente;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AnexoController extends Controller
{
    // Lista branca — só o que o produto realmente precisa (documentos,
    // imagens, planilhas). Nunca lista negra: um tipo novo e desconhecido
    // é rejeitado por padrão, não aceito até alguém lembrar de bloqueá-lo.
    private const EXTENSOES_PERMITIDAS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'csv'];

    // Mime "real" (detectado via magic number, ver validarConteudoRealDoArquivo())
    // aceito por extensão — não é uma lista global única de propósito: sem
    // isso, aceitar 'application/zip' pra docx/xlsx abriria brecha pra um
    // .zip qualquer renomeado como .jpg passar (mesmo tipo global, extensão
    // errada). `application/x-ole-storage`/`application/x-cfb` cobrem
    // doc/xls (formato OLE2 legado — o magic number não diferencia Word de
    // Excel sem inspecionar a estrutura interna completa do documento,
    // então aceitamos o container genérico pros dois). `application/zip`
    // pra docx/xlsx pelo mesmo motivo (são, literalmente, arquivos zip).
    private const MIME_TYPES_POR_EXTENSAO = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/x-cfb'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/x-cfb'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'csv' => ['text/csv', 'text/plain'],
    ];

    public function index(Request $request, Incidente $incidente)
    {
        return AnexoResource::collection(
            $incidente->anexos()
                ->with('user')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request, Incidente $incidente)
    {
        $data = $request->validate([
            'arquivo' => [
                'required',
                'file',
                // 10240 KB = 10 MB — limite arbitrário pra um primeiro corte.
                'max:10240',
                // Whitelist da extensão do nome enviado + bloqueio embutido
                // do Laravel pra .php/.phtml/.phar disfarçados. Sozinha,
                // essa regra NÃO confere o conteúdo real do arquivo (só o
                // nome) — é por isso que o closure abaixo existe.
                'extensions:'.implode(',', self::EXTENSOES_PERMITIDAS),
                function (string $attribute, $value, \Closure $fail) {
                    $this->validarConteudoRealDoArquivo($value, $fail);
                },
            ],
        ]);

        $arquivo = $data['arquivo'];

        // 'caminho' não é fillable de propósito (é o servidor quem gera o
        // nome no disco, nunca vem do cliente) — setado por atribuição
        // direta antes do único save(), pra não deixar a coluna NOT NULL
        // sem valor num primeiro INSERT.
        $anexo = new Anexo([
            'user_id' => $request->user()->id,
            'nome_original' => $arquivo->getClientOriginalName(),
            'mime_type' => $arquivo->getMimeType(),
            'tamanho' => $arquivo->getSize(),
        ]);
        $anexo->incidente_id = $incidente->id;
        $anexo->caminho = $arquivo->store("anexos/incidentes/{$incidente->id}");
        $anexo->save();

        $this->removerMetadadosSeImagem($anexo->caminho, $anexo->mime_type);

        return (new AnexoResource($anexo->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    public function download(Incidente $incidente, Anexo $anexo)
    {
        $this->ensureBelongsTo($incidente, $anexo);

        return Storage::download($anexo->caminho, $anexo->nome_original);
    }

    public function destroy(Incidente $incidente, Anexo $anexo)
    {
        $this->ensureBelongsTo($incidente, $anexo);

        Storage::delete($anexo->caminho);
        $anexo->delete();

        return response()->noContent();
    }

    private function ensureBelongsTo(Incidente $incidente, Anexo $anexo): void
    {
        abort_unless($anexo->incidente_id === $incidente->id, 404);
    }

    /**
     * Confere que o conteúdo real do arquivo (magic number, via
     * `UploadedFile::getMimeType()` — detectado a partir dos bytes, nunca do
     * `Content-Type` que o cliente declarou) bate com o que a extensão
     * enviada promete. Sem isso, um `malware.php` renomeado pra `foto.jpg`
     * passaria pela checagem de extensão (que só olha o nome) e seria
     * aceito — a extensão sozinha não protege contra esse ataque.
     */
    private function validarConteudoRealDoArquivo(UploadedFile $arquivo, \Closure $fail): void
    {
        $extensao = strtolower($arquivo->getClientOriginalExtension());
        $mimesPermitidos = self::MIME_TYPES_POR_EXTENSAO[$extensao] ?? [];

        if (! in_array($arquivo->getMimeType(), $mimesPermitidos, true)) {
            $fail('O conteúdo do arquivo não corresponde à extensão informada.');
        }
    }

    /**
     * Remove metadados (EXIF/GPS/dispositivo em JPEG, chunks ancilares em
     * PNG) recarregando a imagem via GD e resalvando — só pixels sobrevivem,
     * o resto do arquivo original é descartado como efeito colateral do
     * reencode. Evita vazar localização/dispositivo de quem tirou a foto
     * anexada a um chamado. Silenciosamente ignora falha de leitura (a
     * validação de conteúdo já garantiu que é uma imagem de verdade antes
     * daqui — isso é só defesa extra, não deveria falhar em uso normal).
     */
    private function removerMetadadosSeImagem(string $caminho, string $mimeType): void
    {
        $processadores = [
            'image/jpeg' => ['imagecreatefromjpeg', 'imagejpeg'],
            'image/png' => ['imagecreatefrompng', 'imagepng'],
        ];

        if (! isset($processadores[$mimeType])) {
            return;
        }

        [$criar, $salvar] = $processadores[$mimeType];

        $arquivoTemporario = tempnam(sys_get_temp_dir(), 'anexo_exif_');
        file_put_contents($arquivoTemporario, Storage::get($caminho));

        $imagem = @$criar($arquivoTemporario);

        if ($imagem === false) {
            unlink($arquivoTemporario);

            return;
        }

        $salvar($imagem, $arquivoTemporario);
        imagedestroy($imagem);

        Storage::put($caminho, file_get_contents($arquivoTemporario));
        unlink($arquivoTemporario);
    }
}
