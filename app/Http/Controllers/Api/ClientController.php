<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        return ClientResource::collection(
            Client::query()->orderBy('name')->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $client = Client::query()->create($data);

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show(Client $client)
    {
        return new ClientResource($client);
    }

    public function update(Request $request, Client $client)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $client->update($data);

        return new ClientResource($client);
    }

    public function destroy(Client $client)
    {
        if ($client->customers()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir um cliente com customers vinculados.',
            ], 409);
        }

        $client->delete();

        return response()->noContent();
    }
}
