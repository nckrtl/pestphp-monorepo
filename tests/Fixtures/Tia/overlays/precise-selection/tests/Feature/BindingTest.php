<?php

declare(strict_types=1);

test('executes a provider binding', function (): void {
    expect(($this->binding)())->toBe('bound value');
});
