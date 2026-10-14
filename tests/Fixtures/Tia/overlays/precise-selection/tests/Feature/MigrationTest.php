<?php

declare(strict_types=1);

test('executes an orders migration', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_orders.php';
    expect($migration())->toBe('orders');
});
