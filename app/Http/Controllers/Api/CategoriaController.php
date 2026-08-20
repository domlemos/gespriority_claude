<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoriaResource;
use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'nome' => ['sometimes', 'string'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        return CategoriaResource::collection(
            Categoria::query()->filtros($filtros)->orderBy('nome')->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));

        $categoria = Categoria::query()->create($data)->refresh();

        return (new CategoriaResource($categoria))->response()->setStatusCode(201);
    }

    public function show(Categoria $categoria)
    {
        return new CategoriaResource($categoria);
    }

    public function update(Request $request, Categoria $categoria)
    {
        $data = $request->validate($this->rules($request, $categoria));

        $categoria->update($data);

        return new CategoriaResource($categoria);
    }

    public function destroy(Categoria $categoria)
    {
        if ($categoria->subcategorias()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir uma categoria com subcategorias vinculadas.',
            ], 409);
        }

        $categoria->delete();

        return response()->noContent();
    }

    private function rules(Request $request, ?Categoria $categoria = null): array
    {
        return [
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('categorias', 'nome')->ignore($categoria?->id),
            ],
            'ativo' => ['boolean'],
        ];
    }
}
