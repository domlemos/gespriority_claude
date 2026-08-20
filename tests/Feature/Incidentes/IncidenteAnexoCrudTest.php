<?php

namespace Tests\Feature\Incidentes;

use App\Models\Anexo;
use App\Models\Customer;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidenteAnexoCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** @return array{0: string, 1: User} */
    private function staffToken(array $permissionSlugs): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /** PDF minimamente válido (cabeçalho real + padding) até `$bytes` de tamanho. */
    private function pdfContent(int $bytes): string
    {
        $header = "%PDF-1.4\n";

        return $header.str_repeat('A', max(0, $bytes - strlen($header)));
    }

    /**
     * JPEG real (via GD) com um segmento APP1 falso injetado logo após o SOI,
     * simulando metadado (EXIF/GPS) que não deveria sobreviver ao reencode de
     * removerMetadadosSeImagem().
     */
    private function jpegComMarcadorFalso(string $marcador): string
    {
        $imagem = imagecreatetruecolor(10, 10);
        imagefill($imagem, 0, 0, imagecolorallocate($imagem, 100, 150, 200));
        ob_start();
        imagejpeg($imagem);
        $jpegPuro = ob_get_clean();
        imagedestroy($imagem);

        $payload = "Exif\x00\x00".$marcador;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpegPuro, 0, 2).$app1.substr($jpegPuro, 2);
    }

    public function test_staff_with_view_permission_can_list_anexos_of_an_incidente(): void
    {
        $incidente = Incidente::factory()->create();
        Anexo::factory()->count(2)->create(['incidente_id' => $incidente->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes/{$incidente->id}/anexos", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_without_view_permission_cannot_list_anexos(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['some.other.permission']);

        $this->getJson("/api/incidentes/{$incidente->id}/anexos", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_admin_can_upload_an_anexo(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);
        $arquivo = UploadedFile::fake()->createWithContent('relatorio.pdf', $this->pdfContent(100 * 1024));

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertCreated()
            ->assertJsonPath('data.nome_original', 'relatorio.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.tamanho', 100 * 1024)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('anexos', [
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
            'nome_original' => 'relatorio.pdf',
        ]);

        $anexo = Anexo::query()->first();
        Storage::disk('local')->assertExists($anexo->caminho);
    }

    public function test_anexo_still_shows_uploader_name_after_the_user_is_deactivated(): void
    {
        $incidente = Incidente::factory()->create();
        $uploader = User::factory()->create(['name' => 'Ana Souza']);
        Anexo::factory()->create(['incidente_id' => $incidente->id, 'user_id' => $uploader->id]);
        $uploader->delete();
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes/{$incidente->id}/anexos", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.user.name', 'Ana Souza');
    }

    public function test_view_only_permission_cannot_upload_an_anexo(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.view']);
        $arquivo = UploadedFile::fake()->create('relatorio.pdf', 100, 'application/pdf');

        $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_uploading_anexo_requires_a_file(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson("/api/incidentes/{$incidente->id}/anexos", [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('arquivo');
    }

    public function test_uploading_anexo_rejects_files_larger_than_the_limit(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $arquivo = UploadedFile::fake()->create('gigante.pdf', 20000, 'application/pdf');

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('arquivo');
    }

    public function test_uploading_anexo_rejects_a_disallowed_extension(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        // Cabeçalho real de executável Windows (MZ) — nem chega a testar o
        // conteúdo, a extensão sozinha já devia bloquear.
        $arquivo = UploadedFile::fake()->createWithContent('virus.exe', "MZ\x90\x00".str_repeat('A', 100));

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->assertSame(0, Anexo::query()->count());
    }

    public function test_uploading_anexo_rejects_content_that_does_not_match_its_extension(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);

        // UploadedFile::fake() não serve aqui — Illuminate\Http\Testing\File
        // sobrescreve getMimeType() pra devolver o mime baseado só no NOME
        // do arquivo (MimeType::from($this->name)), nunca no conteúdo real,
        // então não exercitaria a checagem de magic number de verdade.
        // Construindo um UploadedFile real (mesma classe usada em produção,
        // getMimeType() delegando pra detecção via finfo nos bytes) —
        // extensão permitida (.jpg), mas conteúdo é um script PHP: é
        // exatamente o ataque "renomear .exe/.php pra .jpg" que
        // validarConteudoRealDoArquivo() existe pra barrar.
        $caminhoTemp = tempnam(sys_get_temp_dir(), 'anexo_teste_');
        file_put_contents($caminhoTemp, "<?php system(\$_GET['cmd']); ?>");
        $arquivo = new UploadedFile($caminhoTemp, 'foto.jpg', 'image/jpeg', null, true);

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->assertSame(0, Anexo::query()->count());
    }

    public function test_uploading_a_real_jpg_image_succeeds(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $arquivo = UploadedFile::fake()->image('foto.jpg', 20, 20);

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.nome_original', 'foto.jpg');
    }

    public function test_uploading_a_real_csv_succeeds(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $arquivo = UploadedFile::fake()->createWithContent('dados.csv', "nome,idade\nJoao,30\nMaria,25\n");

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.nome_original', 'dados.csv');
    }

    public function test_uploading_image_strips_exif_metadata(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $marcador = 'SEGREDO_GPS_LAT_-23.5505_LON_-46.6333';
        $arquivo = UploadedFile::fake()->createWithContent('foto.jpg', $this->jpegComMarcadorFalso($marcador));

        $response = $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );

        $response->assertCreated();
        $anexo = Anexo::query()->first();
        $conteudoSalvo = Storage::disk('local')->get($anexo->caminho);
        $this->assertStringNotContainsString($marcador, $conteudoSalvo);
    }

    public function test_uploading_a_non_image_file_is_not_altered_by_exif_stripping(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $conteudo = $this->pdfContent(1024);
        $arquivo = UploadedFile::fake()->createWithContent('relatorio.pdf', $conteudo);

        $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        )->assertCreated();

        $anexo = Anexo::query()->first();
        $this->assertSame($conteudo, Storage::disk('local')->get($anexo->caminho));
    }

    public function test_staff_can_download_an_anexo(): void
    {
        $incidente = Incidente::factory()->create();
        $anexo = Anexo::factory()->create(['incidente_id' => $incidente->id, 'nome_original' => 'relatorio.pdf']);
        Storage::disk('local')->put($anexo->caminho, 'conteudo de teste');
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->get(
            "/api/incidentes/{$incidente->id}/anexos/{$anexo->id}/download",
            $this->authHeader($token)
        );

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('relatorio.pdf', $response->headers->get('content-disposition'));
    }

    public function test_admin_can_delete_an_anexo(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $arquivo = UploadedFile::fake()->createWithContent('relatorio.pdf', $this->pdfContent(10 * 1024));
        $this->post(
            "/api/incidentes/{$incidente->id}/anexos",
            ['arquivo' => $arquivo],
            $this->authHeader($token)
        );
        $anexo = Anexo::query()->first();

        $response = $this->deleteJson(
            "/api/incidentes/{$incidente->id}/anexos/{$anexo->id}",
            [],
            $this->authHeader($token)
        );

        $response->assertNoContent();
        $this->assertDatabaseMissing('anexos', ['id' => $anexo->id]);
        Storage::disk('local')->assertMissing($anexo->caminho);
    }

    public function test_view_only_permission_cannot_delete_an_anexo(): void
    {
        $incidente = Incidente::factory()->create();
        $anexo = Anexo::factory()->create(['incidente_id' => $incidente->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->deleteJson(
            "/api/incidentes/{$incidente->id}/anexos/{$anexo->id}",
            [],
            $this->authHeader($token)
        )->assertStatus(403);

        $this->assertDatabaseHas('anexos', ['id' => $anexo->id]);
    }

    public function test_anexo_from_a_different_incidente_is_not_found(): void
    {
        $incidenteA = Incidente::factory()->create();
        $incidenteB = Incidente::factory()->create();
        $anexo = Anexo::factory()->create(['incidente_id' => $incidenteB->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->get(
            "/api/incidentes/{$incidenteA->id}/anexos/{$anexo->id}/download",
            $this->authHeader($token)
        )->assertStatus(404);
    }

    public function test_guests_cannot_access_anexos(): void
    {
        $incidente = Incidente::factory()->create();

        $this->getJson("/api/incidentes/{$incidente->id}/anexos")->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_anexos(): void
    {
        $incidente = Incidente::factory()->create();
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson("/api/incidentes/{$incidente->id}/anexos", $this->authHeader($token))
            ->assertStatus(401);
    }
}
