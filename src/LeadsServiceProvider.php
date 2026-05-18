<?php

namespace mojosef\Leads;

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
            ->hasCommand(ResendFailedLeadsCommand::class);

        if (config('leads.schema_owner')) {
            $package->hasMigration('create_leads_table');
        }
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AttributionCollector::class);
        $this->app->singleton(Facebook\FacebookLeadService::class);
        $this->app->singleton(LeadPipeline::class);
    }

    public function packageBooted(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('capture-attribution', CaptureAttribution::class);
    }
}
