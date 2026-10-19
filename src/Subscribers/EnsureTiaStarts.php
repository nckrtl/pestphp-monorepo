<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Tia\Recorder;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * @internal
 */
final readonly class EnsureTiaStarts implements PreparationStartedSubscriber
{
    public function __construct(private Recorder $recorder) {}

    public function notify(PreparationStarted $event): void
    {
        if (! $this->recorder->isActive()) {
            return;
        }

        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        $this->recorder->beginTest($test->className(), $test->methodName(), $test->file());
    }
}
