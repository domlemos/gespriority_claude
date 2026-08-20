<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'nome' => ['sometimes', 'string'],
            'subcategoria_id' => ['sometimes', 'integer', 'exists:subcategorias,id'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        return ItemResource::collection(
            Item::query()
                ->filtros($filtros)
                ->with('subcategoria')
                ->orderBy('subcategoria_id')
                ->orderBy('nome')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));

        $item = Item::query()->create($data)->refresh();

        return (new ItemResource($item->load('subcategoria')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Item $item)
    {
        return new ItemResource($item->load('subcategoria'));
    }

    public function update(Request $request, Item $item)
    {
        $data = $request->validate($this->rules($request, $item));

        $item->update($data);

        return new ItemResource($item->load('subcategoria'));
    }

    public function destroy(Item $item)
    {
        if ($item->incidentes()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir um item com incidentes vinculados.',
            ], 409);
        }

        $item->delete();

        return response()->noContent();
    }

    private function rules(Request $request, ?Item $item = null): array
    {
        $subcategoriaId = $request->input('subcategoria_id', $item?->subcategoria_id);

        return [
            'subcategoria_id' => ['required', 'integer', 'exists:subcategorias,id'],
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('itens', 'nome')
                    ->where('subcategoria_id', $subcategoriaId)
                    ->ignore($item?->id),
            ],
            'ativo' => ['boolean'],
        ];
    }
}
