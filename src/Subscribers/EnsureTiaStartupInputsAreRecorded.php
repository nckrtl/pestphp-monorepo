<?php

declare(strict_types=1);

namespace Pest\Subscribers;

use Pest\Plugins\Tia\Recorder;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/** @internal */
final readonly class EnsureTiaStartupInputsAreRecorded implements ExecutionFinishedSubscriber
{
    public function __construct(private Recorder $recorder) {}

    public function notify(ExecutionFinished $event): void
    {
        if ($this->recorder->isActive()) {
            $this->recorder->recordPreTestFiles();
        }
    }
}
