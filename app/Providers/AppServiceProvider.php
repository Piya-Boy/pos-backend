<?php

namespace App\Providers;

use App\Pos\Sheets\GoogleSheetsClient;
use App\Pos\Sheets\GoogleTokenProvider;
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
        // scoped: one instance per request so the repo's request-scoped read memo
        // is shared across all POS services in that request, and reset between requests.
        $this->app->scoped(SheetRepository::class, fn ($app) => new SheetRepository($app->make(SheetsClient::class)));

        // Real Sheets client in non-test envs. Tests inject FakeSheetsClient via instance().
        if (! $this->app->environment('testing')) {
            $this->app->singleton(SheetsClient::class, fn ($app) => new GoogleSheetsClient($app->make(GoogleTokenProvider::class)));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
