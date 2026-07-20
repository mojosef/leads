<?php

namespace mojosef\Leads\Tests;

use mojosef\Leads\LeadsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LeadsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('leads.site', 'test-site');
        $app['config']->set('leads.connection', 'leads_testing');
    }
}
