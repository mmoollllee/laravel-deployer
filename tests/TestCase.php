<?php

namespace Mmoollllee\LaravelDeployer\Tests;

use Mmoollllee\LaravelDeployer\LaravelDeployerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDeployerServiceProvider::class];
    }
}
