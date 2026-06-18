<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Sanctum\PersonalAccessToken;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run the pruning command daily to clear expired Sanctum tokens
Schedule::command('model:prune', [
    '--model' => [trim(PersonalAccessToken::class, '\\')],
])->daily();

Schedule::call(function () {
    // Find properties created or updated in the last hour
    $properties = Property::where('updated_at', '>=', now()->subHour())->get();
    
    foreach ($properties as $property) {
        DispatchPropertyMatch::dispatch($property);
    }
})->hourly();