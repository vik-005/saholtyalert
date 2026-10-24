<?php

namespace App\Tests\Unit;

use App\Entity\AccessLog;
use App\Entity\User;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AdminAuditLoggerTest extends TestCase
{
    public function testLogsActionAndRequestContextWithoutSensitivePayload(): void
    {
        $actor = new User();
        $request = Request::create('/admin/user/42/edit', 'POST', server: ['REMOTE_ADDR' => '192.0.2.5']);
        $request->headers->set('User-Agent', 'Unit Test');
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($actor);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (AccessLog $log) use ($actor): bool {
                return $log->getUser() === $actor
                    && $log->getAction() === AccessLog::ACTION_MODIFICATION
                    && $log->getDetails() === 'user #42'
                    && $log->getIpAdresse() === '192.0.2.5'
                    && $log->getUserAgent() === 'Unit Test'
                    && $log->getResultat() === 'success';
            }));

        (new AdminAuditLogger($entityManager, $security, $requestStack))
            ->log(AccessLog::ACTION_MODIFICATION, 'user', 42);
    }
}