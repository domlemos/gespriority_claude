<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubcategoriaResource;
use App\Models\Subcategoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubcategoriaController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'nome' => ['sometimes', 'string'],
            'categoria_id' => ['sometimes', 'integer', 'exists:categorias,id'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        return SubcategoriaResource::collection(
            Subcategoria::query()
                ->filtros($filtros)
                ->with('categoria')
                ->orderBy('categoria_id')
                ->orderBy('nome')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));

        $subcategoria = Subcategoria::query()->create($data)->refresh();

        return (new SubcategoriaResource($subcategoria->load('categoria')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Subcategoria $subcategoria)
    {
        return new SubcategoriaResource($subcategoria->load('categoria'));
    }

    public function update(Request $request, Subcategoria $subcategoria)
    {
        $data = $request->validate($this->rules($request, $subcategoria));

        $subcategoria->update($data);

        return new SubcategoriaResource($subcategoria->load('categoria'));
    }

    public function destroy(Subcategoria $subcategoria)
    {
        if ($subcategoria->itens()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir uma subcategoria com itens vinculados.',
            ], 409);
        }

        $subcategoria->delete();

        return response()->noContent();
    }

    private function rules(Request $request, ?Subcategoria $subcategoria = null): array
    {
        $categoriaId = $request->input('categoria_id', $subcategoria?->categoria_id);

        return [
            'categoria_id' => ['required', 'integer', 'exists:categorias,id'],
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('subcategorias', 'nome')
                    ->where('categoria_id', $categoriaId)
                    ->ignore($subcategoria?->id),
            ],
            'ativo' => ['boolean'],
        ];
    }
}
