<?php

namespace App\Message;

class SlaReminderMessage
{
    public function __construct(
        private readonly int $caseId,
        private readonly string $phaseValue,
        private readonly string $type,
    ) {}

    public function getCaseId(): int
    {
        return $this->caseId;
    }

    public function getPhaseValue(): string
    {
        return $this->phaseValue;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
