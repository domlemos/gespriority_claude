<?php

namespace Tests\Feature\Relatorios;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteEventoSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_snapshots_incident_group_and_author_group_on_creation(): void
    {
        $grupoX = GrupoSolucao::factory()->create();
        $grupoY = GrupoSolucao::factory()->create();
        $grupoZ = GrupoSolucao::factory()->create();
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $grupoX->id]);
        $analista = User::factory()->create(['grupo_solucao_id' => $grupoY->id]);

        $evento = IncidenteEvento::create([
            'incidente_id' => $incidente->id,
            'user_id' => $analista->id,
            'tipo' => IncidenteEvento::TIPO_FECHADO,
        ]);

        $incidente->update(['grupo_solucao_id' => $grupoZ->id]);
        $analista->update(['grupo_solucao_id' => $grupoZ->id]);

        $this->assertSame($grupoX->id, $evento->fresh()->grupo_solucao_id);
        $this->assertSame($grupoY->id, $evento->fresh()->autor_grupo_solucao_id);
    }

    public function test_explicit_snapshot_values_are_not_overwritten(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $analista = User::factory()->create(['grupo_solucao_id' => $grupo->id]);

        $evento = IncidenteEvento::create([
            'incidente_id' => $incidente->id,
            'user_id' => $analista->id,
            'tipo' => IncidenteEvento::TIPO_FECHADO,
            'grupo_solucao_id' => null,
            'autor_grupo_solucao_id' => null,
        ]);

        $this->assertNull($evento->fresh()->grupo_solucao_id);
        $this->assertNull($evento->fresh()->autor_grupo_solucao_id);
    }
}
