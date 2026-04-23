<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('import:movies 200')->dailyAt('03:00');

// Tenta rodar o worker a cada minuto, mas só inicia se ele não estiver rodando
Schedule::command('queue:work --stop-when-empty')->everyMinute();