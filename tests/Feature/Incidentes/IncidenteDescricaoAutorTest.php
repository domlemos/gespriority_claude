<?php

namespace Tests\Feature\Incidentes;

use App\Models\Customer;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class IncidenteDescricaoAutorTest extends TestCase
{
    use RefreshDatabase;

    private function entrada(array $autor): IncidenteDescricao
    {
        return IncidenteDescricao::query()->create([
            'incidente_id' => Incidente::factory()->create()->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => 'Texto',
            ...$autor,
        ]);
    }

    public function test_rejects_entry_without_author(): void
    {
        $this->expectException(LogicException::class);

        $this->entrada(['user_id' => null, 'customer_id' => null]);
    }

    public function test_rejects_entry_with_two_authors(): void
    {
        $this->expectException(LogicException::class);

        $this->entrada(['user_id' => User::factory()->create()->id, 'customer_id' => Customer::factory()->create()->id]);
    }

    public function test_accepts_customer_as_sole_author(): void
    {
        $customer = Customer::factory()->create();

        $entrada = $this->entrada(['user_id' => null, 'customer_id' => $customer->id]);

        $this->assertSame($customer->id, $entrada->fresh()->customer_id);
    }
}
