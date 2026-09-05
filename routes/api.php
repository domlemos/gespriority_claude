<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnexoController;
use App\Http\Controllers\Api\GrupoSolucaoController;
use App\Http\Controllers\Api\IncidenteController;
use App\Http\Controllers\Api\IncidenteDescricaoController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\PoliticaSlaController;
use App\Http\Controllers\Api\RelatorioController;
use App\Http\Controllers\Api\RelatorioSalvoController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SubcategoriaController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Staff (guard "web") e Cliente (guard "customer") têm login e recuperação de
// senha em rotas próprias, pois cada guard resolve contra seu próprio model/tabela.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:password-recovery');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:password-recovery');

Route::prefix('customer')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->defaults('guard', 'customer')
        ->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->defaults('guard', 'customer')
        ->middleware('throttle:password-recovery');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->defaults('guard', 'customer')
        ->middleware('throttle:password-recovery');
});

// Sessão autenticada: funciona para qualquer um dos dois guards, resolvido
// pelo token Bearer apresentado (o primeiro guard que autenticar vence).
Route::middleware('auth:web,customer')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
});

// Cliente (empresa contratante) — só staff (guard "web") com a permissão
// dedicada gerencia; Customer nunca acessa (ver BACKEND_SPECS.md seção 3.1).
Route::middleware(['auth:web', 'can:clients.manage'])->apiResource('clients', ClientController::class);

Route::middleware(['auth:web', 'can:users.manage'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::post('/users/{user}/convite', [UserController::class, 'enviarConvite']);
    Route::get('/roles', [RoleController::class, 'index']);
});

Route::middleware(['auth:web', 'can:customers.manage'])->group(function () {
    Route::apiResource('customers', CustomerController::class);
    Route::post('/customers/{customer}/convite', [CustomerController::class, 'enviarConvite']);
});

// Política de SLA — leitura liberada a qualquer staff com `slas.view`
// (agente/supervisor precisam saber os alvos), mas só admin
// (`slas.manage`) cria/altera/exclui (ver BACKEND_SPECS.md seção 3.1).
Route::middleware(['auth:web', 'can:slas.view'])
    ->apiResource('politicas-sla', PoliticaSlaController::class)
    ->parameters(['politicas-sla' => 'politica_sla'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:slas.manage'])
    ->apiResource('politicas-sla', PoliticaSlaController::class)
    ->parameters(['politicas-sla' => 'politica_sla'])
    ->only(['store', 'update', 'destroy']);

// Categoria/Subcategoria de incidentes — mesmo esquema view/manage da
// política de SLA: leitura liberada a quem tem `categorias.view`, escrita
// só a `categorias.manage` (admin). Mesma permission cobre as duas
// entidades — são uma taxonomia só, gerenciada na mesma tela.
Route::middleware(['auth:web', 'can:categorias.view'])->group(function () {
    Route::apiResource('categorias', CategoriaController::class)->only(['index', 'show']);
    Route::apiResource('subcategorias', SubcategoriaController::class)->only(['index', 'show']);
});

Route::middleware(['auth:web', 'can:categorias.manage'])->group(function () {
    Route::apiResource('categorias', CategoriaController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('subcategorias', SubcategoriaController::class)->only(['store', 'update', 'destroy']);
});

// Item — 3º nível da taxonomia de incidentes (Categoria > Subcategoria >
// Item), boa prática de ITSM (granularidade pra roteamento/relatório sem
// achatar tudo em "Subcategoria"). Mesma permission de Categoria/Subcategoria
// — é a mesma taxonomia, só um nível a mais.
Route::middleware(['auth:web', 'can:categorias.view'])
    ->apiResource('itens', ItemController::class)
    ->parameters(['itens' => 'item'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:categorias.manage'])
    ->apiResource('itens', ItemController::class)
    ->parameters(['itens' => 'item'])
    ->only(['store', 'update', 'destroy']);

// Grupo de Solução — todo User pertence a um (ver migration
// add_grupo_solucao_id_to_users_table); Customer é sempre isento. Mesmo
// esquema view/manage dos demais cadastros.
Route::middleware(['auth:web', 'can:grupos_solucao.view'])
    ->apiResource('grupos-solucao', GrupoSolucaoController::class)
    ->parameters(['grupos-solucao' => 'grupo_solucao'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:grupos_solucao.manage'])
    ->apiResource('grupos-solucao', GrupoSolucaoController::class)
    ->parameters(['grupos-solucao' => 'grupo_solucao'])
    ->only(['store', 'update', 'destroy']);

// Incidente — só cadastral nesta entrega (sem relação com PoliticaSla ainda).
// Só staff (guard "web") por enquanto; Customer abrir o próprio chamado pelo
// portal fica pra uma etapa futura (Policy de propriedade, ver BACKEND_SPECS.md
// seção 1.3). Sem rota de exclusão — incidente é registro histórico, "encerra"
// via status (`cancelado`/`fechado`), nunca via DELETE.
Route::middleware(['auth:web', 'can:tickets.view'])
    ->apiResource('incidentes', IncidenteController::class)
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:tickets.manage'])
    ->apiResource('incidentes', IncidenteController::class)
    ->only(['store', 'update']);

// Descrição (feed do incidente) — aninhada em /incidentes/{incidente}/descricoes.
// 'tickets.manage' cobre criar; editar/excluir exige ADICIONALMENTE ser o
// próprio autor da entrada 'comentario' (checado no controller, não dá pra
// expressar "dono do registro" em middleware `can:`) — ver
// IncidenteDescricaoController::ensureCanModify(). Entradas 'escalonamento'
// nunca são editáveis/excluíveis por ninguém (log de sistema).
Route::middleware(['auth:web', 'can:tickets.view'])
    ->apiResource('incidentes.descricoes', IncidenteDescricaoController::class)
    ->parameters(['descricoes' => 'descricao'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:tickets.manage'])
    ->apiResource('incidentes.descricoes', IncidenteDescricaoController::class)
    ->parameters(['descricoes' => 'descricao'])
    ->only(['store', 'update', 'destroy']);

// Anexos — aninhados em /incidentes/{incidente}/anexos. Sem 'update' (arquivo
// é substituído via delete + novo upload, não editado no lugar) e sem
// restrição de autor no destroy (diferente de descricoes/comentario) — não
// foi pedido, então qualquer staff com tickets.manage pode remover.
Route::middleware(['auth:web', 'can:tickets.view'])
    ->apiResource('incidentes.anexos', AnexoController::class)
    ->only(['index']);

Route::middleware(['auth:web', 'can:tickets.view'])
    ->get('/incidentes/{incidente}/anexos/{anexo}/download', [AnexoController::class, 'download']);

Route::middleware(['auth:web', 'can:tickets.manage'])
    ->apiResource('incidentes.anexos', AnexoController::class)
    ->only(['store', 'destroy']);

// Dashboard — visões read-only compostas/achatadas pra listagem, distintas
// do CRUD normal (que devolve o Incidente cru com relações aninhadas). Path
// próprio (/dashboard/...) em vez de /incidentes/dashboard de propósito: uma
// rota GET /incidentes/{incidente} já registrada pelo apiResource acima
// casaria com "dashboard" como se fosse um id antes de chegar aqui.
Route::middleware(['auth:web', 'can:tickets.view'])
    ->get('/dashboard/incidentes', [DashboardController::class, 'incidentes']);

// Relatórios — agregações filtráveis sobre Incidente (SLA por status,
// contagem por agente/categoria/subcategoria/item), formato JSON (gráfico)
// ou .xlsx (planilha, via maatwebsite/excel). 'relatorios.view' cobre tanto
// rodar o relatório quanto ver/executar configurações salvas;
// 'relatorios.manage' cobre criar/editar/excluir a configuração salva —
// rodar não é uma ação de "gerenciar".
Route::middleware(['auth:web', 'can:relatorios.view'])
    ->get('/relatorios/incidentes', [RelatorioController::class, 'index']);

Route::middleware(['auth:web', 'can:relatorios.view'])
    ->apiResource('relatorios-salvos', RelatorioSalvoController::class)
    ->parameters(['relatorios-salvos' => 'relatorioSalvo'])
    ->only(['index', 'show']);

Route::middleware(['auth:web', 'can:relatorios.view'])
    ->get('/relatorios-salvos/{relatorioSalvo}/executar', [RelatorioSalvoController::class, 'executar']);

Route::middleware(['auth:web', 'can:relatorios.manage'])
    ->apiResource('relatorios-salvos', RelatorioSalvoController::class)
    ->parameters(['relatorios-salvos' => 'relatorioSalvo'])
    ->only(['store', 'update', 'destroy']);
