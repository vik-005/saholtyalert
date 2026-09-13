<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
    public function testFindExistingRecentReturnsNullForUnpersistedAlertWithoutQuery(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(NotificationRepository::class);

        // Si l'alerte a un ID null, le repository ne doit même pas être interrogé
        $repo->expects($this->never())->method('findExistingRecent');
        $em->method('getRepository')->willReturn($repo);

        $service = new NotificationService($em);

        $user = new User();
        $alert = new Alert(); // Alert non persistée, getId() === null

        $result = $service->findExistingRecent($user, 'new_alert', $alert);

        $this->assertNull($result);
    }
}
