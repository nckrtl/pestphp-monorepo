<?php

declare(strict_types=1);
use TiaFixture\FixtureTestCase;

$loader = require __DIR__.'/../vendor/autoload.php';
$loader->addPsr4('Fixture\\App\\', __DIR__.'/../app/');
require_once __DIR__.'/FixtureTestCase.stub';

pest()->extend(FixtureTestCase::class)->in('Feature');
