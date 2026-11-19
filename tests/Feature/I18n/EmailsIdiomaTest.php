<?php

namespace Tests\Feature\I18n;

use App\Mail\ChamadoFechadoMail;
use App\Mail\ComentarioDoClienteMail;
use App\Mail\ConviteUsuarioMail;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ChamadoFechado;
use App\Notifications\ComentarioDoCliente;
use App\Notifications\ConviteUsuario;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailsIdiomaTest extends TestCase
{
    use RefreshDatabase;

    private function staffHeaders(string ...$slugs): array
    {
        $role = Role::factory()->create(['slug' => 'admin']);
        foreach ($slugs as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $staff = User::factory()->create();
        $staff->roles()->attach($role);

        return ['Authorization' => 'Bearer '.$staff->createToken('spa', ['staff'], now()->addHour())->plainTextToken];
    }

    private function emLocale(string $locale, callable $fn): mixed
    {
        $anterior = App::getLocale();
        App::setLocale($locale);
        try {
            return $fn();
        } finally {
            App::setLocale($anterior);
        }
    }

    public function test_convite_sai_no_locale_do_destinatario(): void
    {
        Notification::fake();
        $headers = $this->staffHeaders('users.manage');
        $en = User::factory()->create(['locale' => 'en']);
        $pt = User::factory()->create(['locale' => 'pt_BR']);

        $this->postJson("/api/users/{$en->id}/convite", [], $headers)->assertOk();
        $this->postJson("/api/users/{$pt->id}/convite", [], $headers)->assertOk();

        Notification::assertSentTo($en, ConviteUsuario::class, fn ($n, $c, $m, $locale) => $locale === 'en');
        Notification::assertSentTo($pt, ConviteUsuario::class, fn ($n, $c, $m, $locale) => $locale === 'pt_BR');
    }

    public function test_convite_renderiza_em_en_e_pt(): void
    {
        config(['app.name' => 'Gespriority']);
        $mail = new ConviteUsuarioMail('Ana', 'https://x.test/u');

        $en = $this->emLocale('en', fn () => [$mail->envelope()->subject, $mail->render()]);
        $this->assertSame('You have been invited to Gespriority', $en[0]);
        $this->assertStringContainsString('Set my password', $en[1]);
        $this->assertStringNotContainsString('Definir minha senha', $en[1]);

        $pt = $this->emLocale('pt_BR', fn () => [$mail->envelope()->subject, $mail->render()]);
        $this->assertSame('Você foi convidado para o Gespriority', $pt[0]);
        $this->assertStringContainsString('Definir minha senha', $pt[1]);
        $this->assertStringContainsString('Olá, Ana', $pt[1]);
    }

    public function test_chamado_fechado_vai_no_locale_do_customer_mesmo_com_analista_em_pt(): void
    {
        Notification::fake();
        $headers = $this->staffHeaders('tickets.view', 'tickets.manage', 'tickets.edit_all') + ['Accept-Language' => 'pt-BR'];
        $customer = Customer::factory()->create(['locale' => 'en']);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'resolvido']);

        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'fechado', 'mensagem_encerramento' => 'Fixed.'], $headers)->assertOk();

        Notification::assertSentTo($customer, ChamadoFechado::class, fn ($n, $c, $m, $locale) => $locale === 'en');
    }

    public function test_chamado_fechado_renderiza_em_en_e_pt(): void
    {
        $mail = new ChamadoFechadoMail('Bia', 7, 'Rede', 'Feito.', 7, 'https://x.test/c');

        $en = $this->emLocale('en', fn () => [$mail->envelope()->subject, $mail->render()]);
        $this->assertSame('[#7] Your ticket has been closed: Rede', $en[0]);
        $this->assertStringContainsString('View ticket', $en[1]);

        $pt = $this->emLocale('pt_BR', fn () => [$mail->envelope()->subject, $mail->render()]);
        $this->assertSame('[#7] Seu chamado foi fechado: Rede', $pt[0]);
        $this->assertStringContainsString('Ver o chamado', $pt[1]);
        $this->assertStringContainsString('Olá, Bia. Seu chamado foi fechado pela equipe de suporte', $pt[1]);
        $this->assertStringContainsString('em até 7 dias', $pt[1]);
    }

    public function test_comentario_do_cliente_vai_no_locale_de_cada_destinatario(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $grupo = GrupoSolucao::factory()->create();
        $pt = User::factory()->create(['grupo_solucao_id' => $grupo->id, 'locale' => 'pt_BR']);
        $en = User::factory()->create(['grupo_solucao_id' => $grupo->id, 'locale' => 'en']);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'aberto', 'grupo_solucao_id' => $grupo->id, 'responsavel_id' => null]);
        $token = $customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;

        $this->postJson("/api/customer/incidentes/{$incidente->id}/comentarios", ['descricao' => 'Oi'], ['Authorization' => "Bearer {$token}"])->assertCreated();

        Notification::assertSentTo($pt, ComentarioDoCliente::class, fn ($n, $c, $m, $locale) => $locale === 'pt_BR');
        Notification::assertSentTo($en, ComentarioDoCliente::class, fn ($n, $c, $m, $locale) => $locale === 'en');
    }

    public function test_comentario_do_cliente_renderiza_em_en_e_pt(): void
    {
        $novo = new ComentarioDoClienteMail('Caio', 3, 'VPN', 'Bia', 'Acme', 'Ainda falha', false, 'https://x.test/i');
        $reab = new ComentarioDoClienteMail('Caio', 3, 'VPN', 'Bia', 'Acme', 'Voltou', true, 'https://x.test/i');

        $this->assertSame('[#3] New customer comment: VPN', $this->emLocale('en', fn () => $novo->envelope()->subject));
        $this->assertSame('[#3] Ticket reopened by the customer: VPN', $this->emLocale('en', fn () => $reab->envelope()->subject));
        $this->assertSame('[#3] Novo comentário do cliente: VPN', $this->emLocale('pt_BR', fn () => $novo->envelope()->subject));
        $this->assertSame('[#3] Chamado reaberto pelo cliente: VPN', $this->emLocale('pt_BR', fn () => $reab->envelope()->subject));

        $htmlPt = $this->emLocale('pt_BR', fn () => $reab->render());
        $this->assertStringContainsString('reabriu este chamado pelo portal:', $htmlPt);
        $this->assertStringContainsString('Abrir o chamado', $htmlPt);
        $htmlEn = $this->emLocale('en', fn () => $novo->render());
        $this->assertStringContainsString('commented on this ticket through the portal:', $htmlEn);
        $this->assertStringContainsString('Open ticket', $htmlEn);
    }

    public function test_esqueci_a_senha_usa_o_idioma_da_requisicao(): void
    {
        Notification::fake();
        $user = User::factory()->create(['locale' => 'pt_BR']);

        $this->postJson('/api/forgot-password', ['email' => $user->email], ['Accept-Language' => 'en'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, fn ($n, $c, $m, $locale) => $locale === 'en');
    }

    public function test_esqueci_a_senha_do_customer_usa_o_idioma_da_requisicao(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['locale' => 'en']);

        $this->postJson('/api/customer/forgot-password', ['email' => $customer->email], ['Accept-Language' => 'pt-BR'])->assertOk();

        Notification::assertSentTo($customer, ResetPassword::class, fn ($n, $c, $m, $locale) => $locale === 'pt_BR');
    }

    public function test_esqueci_a_senha_sem_header_sai_em_pt_com_assunto_traduzido(): void
    {
        Notification::fake();
        $user = User::factory()->create(['locale' => 'en']);

        $this->postJson('/api/forgot-password', ['email' => $user->email], ['Accept-Language' => ''])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, fn ($n, $c, $m, $locale) => $locale === 'pt_BR');

        $mensagem = $this->emLocale('pt_BR', fn () => (new ResetPassword('tok'))->toMail($user));
        $this->assertSame('Redefinir sua senha', $mensagem->subject);
        $this->assertSame('Redefinir senha', $mensagem->actionText);
        $this->assertSame(
            'Se você não solicitou a redefinição de senha, nenhuma ação adicional é necessária.',
            $mensagem->outroLines[1],
        );
        $this->assertSame('Reset your password', $this->emLocale('en', fn () => (new ResetPassword('tok'))->toMail($user)->subject));
    }
}
