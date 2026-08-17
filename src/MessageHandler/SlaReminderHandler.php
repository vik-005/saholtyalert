<?php

namespace App\MessageHandler;

use App\Message\SlaReminderMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SlaReminderHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SlaReminderMessage $message): void
    {
        $this->logger->warning(sprintf(
            'SLA Reminder/Violation for UrgenceCase #%d, Phase %s: %s',
            $message->getCaseId(),
            $message->getPhaseValue(),
            $message->getType()
        ));
    }
}
