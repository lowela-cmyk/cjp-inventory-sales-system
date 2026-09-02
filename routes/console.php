<?php

use App\Services\AIService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:test-connection', function (AIService $ai): int {
    $result = $ai->testConnection();

    if ($result['ok']) {
        $this->info('AI connection successful.');

        return 0;
    }

    $this->error('AI connection failed: '.$result['error']);

    return 1;
})->purpose('Test the configured AI provider without exposing credentials');
