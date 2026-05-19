<?php

namespace mojosef\Leads\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MigrateCommand extends Command
{
    protected $signature = 'leads:migrate
        {--rollback : Roll the package migration back instead of running it}
        {--pretend : Show the SQL that would be run, without executing}
        {--force : Run in production without confirmation}';

    protected $description = 'Run the leads package migrations against the shared database. Only the schema-owner site should run this.';

    public function handle(): int
    {
        if (! config('leads.schema_owner')) {
            $this->warn('LEADS_SCHEMA_OWNER is not true on this site — skipping.');
            $this->line('Only the designated schema-owner site should run leads migrations.');
            return self::SUCCESS;
        }

        $connection = config('leads.connection');
        $path = $this->resolveMigrationsPath();

        $options = [
            '--database' => $connection,
            '--path' => $path,
            '--realpath' => true,
        ];

        if ($this->option('pretend')) {
            $options['--pretend'] = true;
        }

        if ($this->option('force') || $this->laravel->environment('production')) {
            $options['--force'] = true;
        }

        $artisanCommand = $this->option('rollback') ? 'migrate:rollback' : 'migrate';

        $this->info(sprintf(
            '%s leads migrations on connection [%s]%s',
            $this->option('rollback') ? 'Rolling back' : 'Running',
            $connection,
            $this->option('pretend') ? ' (pretend)' : '',
        ));

        return Artisan::call($artisanCommand, $options, $this->output);
    }

    private function resolveMigrationsPath(): string
    {
        return realpath(__DIR__.'/../../database/migrations')
            ?: __DIR__.'/../../database/migrations';
    }
}
