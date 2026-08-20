<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidenteDescricaoResource;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use Illuminate\Http\Request;

class IncidenteDescricaoController extends Controller
{
    public function index(Request $request, Incidente $incidente)
    {
        return IncidenteDescricaoResource::collection(
            $incidente->descricoes()
                ->with('user')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request, Incidente $incidente)
    {
        $data = $request->validate([
            'descricao' => ['required', 'string'],
        ]);

        // tipo e user_id nunca vêm do cliente — todo comentário criado por
        // aqui é 'comentario' (o único tipo que essa rota cria; 'escalonamento'
        // só é gerado automaticamente pelo IncidenteController::update()) e o
        // autor é sempre quem está autenticado, nunca um user_id arbitrário.
        $descricao = $incidente->descricoes()->create([
            'user_id' => $request->user()->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => $data['descricao'],
        ]);

        return (new IncidenteDescricaoResource($descricao->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Incidente $incidente, IncidenteDescricao $descricao)
    {
        $this->ensureBelongsTo($incidente, $descricao);

        return new IncidenteDescricaoResource($descricao->load('user'));
    }

    public function update(Request $request, Incidente $incidente, IncidenteDescricao $descricao)
    {
        $this->ensureBelongsTo($incidente, $descricao);
        $this->ensureCanModify($request, $descricao);

        $data = $request->validate([
            'descricao' => ['required', 'string'],
        ]);

        $descricao->update($data);

        return new IncidenteDescricaoResource($descricao->load('user'));
    }

    public function destroy(Request $request, Incidente $incidente, IncidenteDescricao $descricao)
    {
        $this->ensureBelongsTo($incidente, $descricao);
        $this->ensureCanModify($request, $descricao);

        $descricao->delete();

        return response()->noContent();
    }

    private function ensureBelongsTo(Incidente $incidente, IncidenteDescricao $descricao): void
    {
        abort_unless($descricao->incidente_id === $incidente->id, 404);
    }

    /**
     * Só o próprio autor edita/exclui, e só entradas 'comentario' — um
     * 'escalonamento' é log de sistema, nunca editável/excluível por
     * ninguém, nem pelo usuário que disparou a ação. Um comentário já
     * excluído (soft delete) também não pode ser editado nem excluído de
     * novo — continua visível no feed (ver Incidente::descricoes()), mas
     * congelado.
     */
    private function ensureCanModify(Request $request, IncidenteDescricao $descricao): void
    {
        abort_unless(
            $descricao->tipo === IncidenteDescricao::TIPO_COMENTARIO
                && $descricao->user_id === $request->user()->id,
            403,
            'Só é possível editar ou excluir a própria anotação.'
        );

        abort_if($descricao->trashed(), 403, 'Este comentário já foi excluído.');
    }
}
