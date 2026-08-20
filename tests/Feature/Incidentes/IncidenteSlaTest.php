<?php

namespace Tests\Feature\Incidentes;

use App\Models\Incidente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteSlaTest extends TestCase
{
    use RefreshDatabase;


    public function test_status_sla_is_sem_sla_when_there_is_no_deadline(): void
    {
        $incidente = Incidente::factory()->make(['prazo_resposta' => null, 'prazo_resolucao' => null]);

        $this->assertSame('sem_sla', $incidente->statusSlaResposta());
        $this->assertSame('sem_sla', $incidente->statusSlaResolucao());
    }

    public function test_tempo_restante_is_null_when_there_is_no_deadline(): void
    {
        $incidente = Incidente::factory()->make(['prazo_resposta' => null, 'prazo_resolucao' => null]);

        $this->assertNull($incidente->tempoRestanteRespostaMinutos());
        $this->assertNull($incidente->tempoRestanteResolucaoMinutos());
    }

    public function test_status_sla_is_dentro_prazo_when_deadline_is_in_the_future_and_incidente_still_open(): void
    {
        $incidente = Incidente::factory()->make([
            'prazo_resposta' => now()->addMinutes(30),
            'respondido_em' => null,
        ]);

        $this->assertSame('dentro_prazo', $incidente->statusSlaResposta());
    }

    public function test_status_sla_is_estourado_when_deadline_is_in_the_past_and_incidente_still_open(): void
    {
        $incidente = Incidente::factory()->make([
            'prazo_resposta' => now()->subMinutes(30),
            'respondido_em' => null,
        ]);

        $this->assertSame('estourado', $incidente->statusSlaResposta());
    }

    public function test_tempo_restante_is_positive_when_deadline_is_in_the_future(): void
    {
        $incidente = Incidente::factory()->make([
            'prazo_resposta' => now()->addMinutes(45),
            'respondido_em' => null,
        ]);

        $this->assertGreaterThanOrEqual(44, $incidente->tempoRestanteRespostaMinutos());
        $this->assertLessThanOrEqual(45, $incidente->tempoRestanteRespostaMinutos());
    }

    public function test_tempo_restante_is_negative_when_deadline_is_in_the_past(): void
    {
        $incidente = Incidente::factory()->make([
            'prazo_resposta' => now()->subMinutes(45),
            'respondido_em' => null,
        ]);

        $this->assertLessThan(0, $incidente->tempoRestanteRespostaMinutos());
    }

    public function test_status_sla_resolucao_uses_concluido_em_as_a_frozen_reference_instead_of_now(): void
    {
        // Prazo já estourado *agora*, mas foi concluído bem antes do prazo —
        // o status precisa refletir o momento da conclusão, não o presente.
        $incidente = Incidente::factory()->make([
            'prazo_resolucao' => now()->subMinutes(10),
            'concluido_em' => now()->subMinutes(60),
        ]);

        $this->assertSame('dentro_prazo', $incidente->statusSlaResolucao());
    }

    public function test_status_sla_resolucao_reflects_estourado_if_concluded_after_the_deadline(): void
    {
        $incidente = Incidente::factory()->make([
            'prazo_resolucao' => now()->subMinutes(60),
            'concluido_em' => now()->subMinutes(10),
        ]);

        $this->assertSame('estourado', $incidente->statusSlaResolucao());
    }

    public function test_status_sla_resposta_uses_respondido_em_as_a_frozen_reference_instead_of_now(): void
    {
        $incidente = Incidente::factory()->make([
            'prazo_resposta' => now()->subMinutes(10),
            'respondido_em' => now()->subMinutes(60),
        ]);

        $this->assertSame('dentro_prazo', $incidente->statusSlaResposta());
    }

    public function test_tempo_restante_resolucao_uses_concluido_em_as_a_frozen_reference_instead_of_now(): void
    {
        $incidente = Incidente::factory()->make([
            'prazo_resolucao' => now()->addMinutes(60),
            'concluido_em' => now()->subMinutes(30),
        ]);

        // Concluído bem antes do prazo (30min "antes de agora" vs prazo
        // 60min "depois de agora") — sobravam ~90min no momento da conclusão.
        $this->assertGreaterThanOrEqual(89, $incidente->tempoRestanteResolucaoMinutos());
        $this->assertLessThanOrEqual(90, $incidente->tempoRestanteResolucaoMinutos());
    }
}
