<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->database->query('select * from orders');
});

test('uses orders queried in beforeEach', function (): void {
    expect($this->config['value'])->toBe('configured');
});
