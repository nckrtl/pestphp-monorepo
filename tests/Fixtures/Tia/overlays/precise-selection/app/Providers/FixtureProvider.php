<?php

declare(strict_types=1);

namespace TiaFixture;

final class FixtureProvider
{
    public function register(): \Closure
    {
        return static fn (): string => 'bound value';
    }

    public function boot(): string
    {
        return 'booted';
    }
}
