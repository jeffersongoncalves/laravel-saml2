<?php

declare(strict_types=1);

namespace JeffersonGoncalves\LaravelSaml2\Tests;

use JeffersonGoncalves\LaravelSaml2\Saml2ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [Saml2ServiceProvider::class];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('app.url', 'http://localhost');
    }
}
