<?php

namespace mojosef\Leads;

use mojosef\Leads\Console\DispatchPendingLeadsCommand;
use mojosef\Leads\Console\FinalizeDraftsCommand;
use mojosef\Leads\Console\MigrateCommand;
use mojosef\Leads\Console\ResendFailedLeadsCommand;
use mojosef\Leads\Http\Middleware\CaptureAttribution;
use Illuminate\Routing\Router;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LeadsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('leads')
            ->hasConfigFile('leads')
            ->hasCommand(ResendFailedLeadsCommand::class)
            ->hasCommand(MigrateCommand::class)
            ->hasCommand(DispatchPendingLeadsCommand::class)
            ->hasCommand(FinalizeDraftsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AttributionCollector::class);
        $this->app->singleton(Facebook\FacebookLeadService::class);
        $this->app->singleton(LeadDispatcher::class);
        $this->app->singleton(LeadPipeline::class);
    }

    public function packageBooted(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('capture-attribution', CaptureAttribution::class);

        // Migrations are NOT auto-loaded. The schema-owner site runs them via
        // the dedicated `leads:migrate` command, which targets the leads
        // connection so the migration record lands in the shared database
        // alongside the table itself.
    }
}
