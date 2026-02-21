<?php

namespace Woda\Ralph\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Woda\Ralph\RalphServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RalphServiceProvider::class,
        ];
    }
}
