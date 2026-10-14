<?php

declare(strict_types=1);
use TiaFixture\FixtureTestCase;

require_once __DIR__.'/../app/Calculator.php';
require_once __DIR__.'/../app/Greeter.php';
require_once __DIR__.'/../app/Providers/FixtureProvider.php';
require_once __DIR__.'/FixtureTestCase.stub';

pest()->extend(FixtureTestCase::class)->in('Feature');
