<?php

namespace App\Message;

class AlertEscalationMessage
{
    public function __construct(
        private readonly int $alertId,
        private readonly string $type,
        private readonly array $details = [],
    ) {}

    public function getAlertId(): int
    {
        return $this->alertId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
