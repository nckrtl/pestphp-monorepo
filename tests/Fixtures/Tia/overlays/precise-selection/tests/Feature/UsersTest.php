<?php

declare(strict_types=1);

test('queries users', function (): void {
    $this->database->query('select * from users');
    expect(true)->toBeTrue();
});
