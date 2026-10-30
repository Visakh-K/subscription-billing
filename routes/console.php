<?php

use App\Jobs\AggregateDailyUsageJob;
use App\Jobs\GenerateCycleEndInvoicesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 1. Nightly Scheduler (Matches Wireframe: "Nightly aggregation job: queued, chunked (15k rows/batch)")
Schedule::job(new AggregateDailyUsageJob(now()->subDay()->toDateString()))
    ->dailyAt('00:05')
    ->name('aggregate-daily-usage')
    ->withoutOverlapping();

Schedule::job(new GenerateCycleEndInvoicesJob)
    ->dailyAt('00:30')
    ->name('generate-cycle-end-invoices')
    ->withoutOverlapping();

// 2. Manual CLI Command for easy testing & video walkthrough
Artisan::command('billing:process {--date= : The YYYY-MM-DD date to aggregate}', function () {
    $date = $this->option('date') ?? now()->toDateString();

    $this->info("Dispatching daily usage aggregation for {$date}...");
    AggregateDailyUsageJob::dispatchSync($date);

    $this->info('Dispatching cycle-end invoice generation...');
    GenerateCycleEndInvoicesJob::dispatchSync();

    $this->info('Done! Daily usage aggregated and due invoices generated.');
})->purpose('Aggregate daily usage and generate due cycle-end invoices');
