<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\ConviteUsuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'name' => ['sometimes', 'string'],
            'email' => ['sometimes', 'string'],
            'grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
        ]);

        return UserResource::collection(
            User::query()
                ->filtros($filtros)
                ->with(['roles.permissions', 'grupoSolucao'])
                ->orderBy('name')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            // Sem senha informada: gera um hash aleatório inutilizável — o
            // usuário só entra depois de definir a própria via convite (ver
            // enviarConvite() e BACKEND_SPECS.md seção 3.4.3.1).
            'password' => Hash::make($data['password'] ?? Str::password(40)),
            'grupo_solucao_id' => $data['grupo_solucao_id'],
        ]);

        $user->roles()->sync($data['role_ids'] ?? []);

        return (new UserResource($user->load('roles.permissions', 'grupoSolucao')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user)
    {
        return new UserResource($user->load('roles.permissions', 'grupoSolucao'));
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'grupo_solucao_id' => $data['grupo_solucao_id'],
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids']);
        }

        return new UserResource($user->load('roles.permissions', 'grupoSolucao'));
    }

    public function enviarConvite(User $user)
    {
        $token = Password::broker('users')->createToken($user);

        $user->notify(new ConviteUsuario($token));

        return response()->json(['message' => 'Convite enviado.']);
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Não é possível desativar a própria conta.',
            ], 409);
        }

        // Soft delete — desativa, não apaga. Por isso não há mais checagem
        // de "é responsável por incidente"/"enviou anexo": o registro
        // continua existindo (só com deleted_at setado), então essas
        // referências nunca ficam órfãs (ver User::incidentesResponsavel(),
        // Incidente::responsavel()/IncidenteDescricao::user()/Anexo::user(),
        // que usam withTrashed() pra continuar resolvendo o nome).
        $user->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            // Opcional em ambos os casos: na criação, ausente = conta criada
            // sem senha utilizável, pendente de convite (ver enviarConvite()).
            'password' => ['nullable', 'string', 'min:8'],
            'grupo_solucao_id' => ['required', 'integer', 'exists:grupos_solucao,id'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);
    }
}
