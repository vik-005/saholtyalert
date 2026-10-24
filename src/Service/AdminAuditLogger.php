<?php

namespace App\Service;

use App\Entity\AccessLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdminAuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {}

    public function log(string $action, string $target, int|string|null $targetId = null): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $actor = $this->security->getUser();

        $log = new AccessLog();
        $log->setUser($actor instanceof User ? $actor : null);
        $log->setAction($action);
        $log->setResultat('success');
        $log->setDetails(sprintf('%s%s', $target, $targetId !== null ? sprintf(' #%s', $targetId) : ''));
        $log->setIpAdresse($request?->getClientIp());
        $log->setUserAgent($request?->headers->get('User-Agent'));

        $this->entityManager->persist($log);
    }

    public function logAndFlush(string $action, string $target, int|string|null $targetId = null): void
    {
        $this->log($action, $target, $targetId);
        $this->entityManager->flush();
    }
}