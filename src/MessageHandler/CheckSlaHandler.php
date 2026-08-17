<?php

namespace App\MessageHandler;

use App\Message\CheckSlaMessage;
use App\Service\SlaMonitorService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CheckSlaHandler
{
    public function __construct(
        private readonly SlaMonitorService $slaMonitor,
    ) {}

    public function __invoke(CheckSlaMessage $message): void
    {
        $this->slaMonitor->checkAll();
    }
}
