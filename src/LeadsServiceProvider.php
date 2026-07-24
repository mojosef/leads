<?php

namespace mojosef\Leads;

use Illuminate\Routing\Router;
use mojosef\Leads\Console\FinalizeDraftsCommand;
use mojosef\Leads\Console\HealthCommand;
use mojosef\Leads\ContactForm\CrmMapper;
use mojosef\Leads\ContactForm\FormDefinition;
use mojosef\Leads\ContactForm\FormValidator;
use mojosef\Leads\Http\Middleware\CaptureAttribution;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LeadsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('leads')
            ->hasConfigFile('leads')
            ->hasCommand(FinalizeDraftsCommand::class)
            ->hasCommand(HealthCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AttributionCollector::class);
        $this->app->singleton(LeadPipeline::class);
        $this->app->singleton(FormDefinition::class);
        $this->app->singleton(FormValidator::class);
        $this->app->singleton(CrmMapper::class);

        $this->registerSharedConnections();
    }

    /**
     * Register the fleet-shared database connection so every site picks it up
     * from env alone — no per-site config edits. Each registration is guarded:
     * if the host app already defines the same key, that wins, so existing
     * sites and bespoke overrides remain unaffected.
     *
     * The package no longer queues any jobs, so no Redis or queue connection
     * is registered here.
     */
    private function registerSharedConnections(): void
    {
        $config = $this->app['config'];

        if (! $config->has('database.connections.leads')) {
            $config->set('database.connections.leads', [
                'driver' => 'mysql',
                'host' => env('LEADS_DB_HOST', '127.0.0.1'),
                'port' => env('LEADS_DB_PORT', '3306'),
                'database' => env('LEADS_DB_DATABASE', 'leads_shared'),
                'username' => env('LEADS_DB_USERNAME', env('DB_USERNAME', 'forge')),
                'password' => env('LEADS_DB_PASSWORD', env('DB_PASSWORD', '')),
                'unix_socket' => env('LEADS_DB_SOCKET', env('DB_SOCKET', '')),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ]);
        }

        if (! $config->has('database.connections.leads_testing')) {
            $config->set('database.connections.leads_testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
    }

    public function packageBooted(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('capture-attribution', CaptureAttribution::class);

        // Contact-form wording is registered under the `contact-form`
        // namespace (not the package short name) so sites override it in
        // lang/vendor/contact-form/{locale}/form.php. Only labels change —
        // submitted values always come from the enums.
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'contact-form');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/contact-form'),
        ], 'leads-translations');

        // The package ships no migrations. Duo owns the shared database
        // schema; the package just reads and writes the leads table.
    }
}
