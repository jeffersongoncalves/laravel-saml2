<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use JeffersonGoncalves\LaravelSaml2\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
