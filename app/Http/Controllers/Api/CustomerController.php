<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Notifications\ConviteUsuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'name' => ['sometimes', 'string'],
            'email' => ['sometimes', 'string'],
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
        ]);

        return CustomerResource::collection(
            Customer::query()
                ->filtros($filtros)
                ->with('client')
                ->orderBy('name')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $customer = Customer::query()->create([
            'client_id' => $data['client_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            // Sem senha informada: gera um hash aleatório inutilizável — o
            // customer só entra depois de definir a própria via convite (ver
            // enviarConvite() e BACKEND_SPECS.md seção 3.4.3.1).
            'password' => Hash::make($data['password'] ?? Str::password(40)),
        ]);

        return (new CustomerResource($customer->load('client')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer)
    {
        return new CustomerResource($customer->load('client'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $this->validated($request, $customer);

        $customer->fill([
            'client_id' => $data['client_id'],
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if (! empty($data['password'])) {
            $customer->password = Hash::make($data['password']);
        }

        $customer->save();

        return new CustomerResource($customer->load('client'));
    }

    public function enviarConvite(Customer $customer)
    {
        $token = Password::broker('customers')->createToken($customer);

        $customer->notify(new ConviteUsuario($token));

        return response()->json(['message' => 'Convite enviado.']);
    }

    public function destroy(Customer $customer)
    {
        if ($customer->incidentes()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir um customer com incidentes vinculados.',
            ], 409);
        }

        $customer->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('customers', 'email')->ignore($customer?->id),
            ],
            // Opcional em ambos os casos: na criação, ausente = conta criada
            // sem senha utilizável, pendente de convite (ver enviarConvite()).
            'password' => ['nullable', 'string', 'min:8'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);
    }
}
