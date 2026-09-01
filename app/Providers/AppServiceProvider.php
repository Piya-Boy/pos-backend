<?php

namespace App\Providers;

use App\Pos\Sheets\SheetRepository;
use App\Pos\Sheets\SheetsClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SheetsClient: real GoogleSheetsClient in prod (bound in Task 10).
        // In testing, feature tests bind a FakeSheetsClient via $this->app->instance().
        // SheetRepository + POS services resolve the bound SheetsClient.
        $this->app->bind(SheetRepository::class, fn ($app) => new SheetRepository($app->make(SheetsClient::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
