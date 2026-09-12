<?php

namespace App\Policies;

use App\Models\Incidente;
use App\Models\User;

class IncidentePolicy
{
    /**
     * `admin` não tem restrição (visibleGrupoSolucaoIds() === null); um
     * incidente sem grupo (triagem pendente) é visível a qualquer staff
     * com a permission de rota — a mesma regra de Incidente::scopeVisiveisPara().
     */
    public function view(User $user, Incidente $incidente): bool
    {
        $ids = $user->visibleGrupoSolucaoIds();

        return $ids === null
            || $incidente->grupo_solucao_id === null
            || in_array($incidente->grupo_solucao_id, $ids, true);
    }

    public function update(User $user, Incidente $incidente): bool
    {
        return $this->view($user, $incidente);
    }
}
