<?php

use App\Models\AuditLog;
use Database\Seeders\ChamadosDemoSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Massa de demonstração de chamados (Incidente/Solicitação) — ver
// Database\Seeders\ChamadosDemoSeeder. Fora do `db:seed` de propósito: não é
// idempotente (cada execução cria mais chamados). Em produção pede
// confirmação, a menos que venha `--force`.
Artisan::command('chamados:popular {--quantidade=100 : Quantos chamados criar} {--force : Não pedir confirmação em produção}', function () {
    if (app()->isProduction() && ! $this->option('force') && ! $this->confirm('Deseja realmente executar este comando?')) {
        $this->warn('Cancelado.');

        return 1;
    }

    $quantidade = (int) $this->option('quantidade');

    if ($quantidade < 1) {
        $this->error('--quantidade precisa ser um inteiro maior que zero.');

        return 1;
    }

    $seeder = app(ChamadosDemoSeeder::class);
    $seeder->quantidade = $quantidade;
    $seeder->setCommand($this)->__invoke();

    $this->info("{$quantidade} chamados de demonstração criados.");

    return 0;
})->purpose('Cria chamados de demonstração (incidentes e solicitações) sobre a taxonomia cadastrada');

// Retenção do log de ações: apaga o que passou de AuditLog::RETENCAO_MESES
// (6 meses). Roda no container `scheduler` (docker-compose.vps.yml).
Schedule::command('model:prune', ['--model' => [AuditLog::class]])
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
