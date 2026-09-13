<?php

namespace App\MessageHandler;

use App\Message\SlaReminderMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

#[AsMessageHandler]
class SlaReminderHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
    ) {}

    public function __invoke(SlaReminderMessage $message): void
    {
        $this->logger->warning(sprintf(
            'SLA Reminder/Violation for UrgenceCase #%d, Phase %s: %s',
            $message->getCaseId(),
            $message->getPhaseValue(),
            $message->getType()
        ));

        if (in_array($message->getType(), ['depassement', 'escalade_comite_ait'], true)) {
            $this->sendSlaViolationEmail($message);
        }
    }

    private function sendSlaViolationEmail(SlaReminderMessage $message): void
    {
        $html = $this->twig->render('emails/sla_violation.html.twig', [
            'caseId'     => $message->getCaseId(),
            'phase'      => $message->getPhaseValue(),
            'type'       => $message->getType(),
        ]);

        $email = (new Email())
            ->from('gei@example.com')
            ->to('pft@example.com')
            ->subject(sprintf('[SLA] Dépassement urgence 72h — Cas #%d', $message->getCaseId()))
            ->html($html);

        $this->mailer->send($email);
    }
}
