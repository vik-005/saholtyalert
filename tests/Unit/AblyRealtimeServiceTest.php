<?php

namespace App\Tests\Unit;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\AblyRealtimeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AblyRealtimeServiceTest extends TestCase
{
    public function testTokenRequestIsSignedAndScopedToTheUserChannel(): void
    {
        $service = new AblyRealtimeService(new MockHttpClient(), new NullLogger(), 'app.key:secret');
        $user = $this->persistedUser(42);

        $tokenRequest = $service->createTokenRequest($user);
        $this->assertSame('app.key', $tokenRequest['keyName']);
        $this->assertSame(3_600_000, $tokenRequest['ttl']);
        $this->assertSame('{"gei-user-42":["subscribe"]}', $tokenRequest['capability']);
        $this->assertSame('42', $tokenRequest['clientId']);
        $this->assertSame(
            base64_encode(hash_hmac(
                'sha256',
                implode("\n", [
                    $tokenRequest['keyName'],
                    $tokenRequest['ttl'],
                    $tokenRequest['capability'],
                    $tokenRequest['clientId'],
                    $tokenRequest['timestamp'],
                    $tokenRequest['nonce'],
                ]),
                'secret',
                true,
            )),
            $tokenRequest['mac'],
        );
    }

    public function testPublishSendsOnlyThePersistedNotificationIdToItsPrivateChannel(): void
    {
        $request = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$request): MockResponse {
            $request = [$method, $url, $options];

            return new MockResponse('', ['http_code' => 201]);
        });
        $service = new AblyRealtimeService($httpClient, new NullLogger(), 'app.key:secret');
        $notification = new Notification();
        $notification->setDestinataire($this->persistedUser(7));
        $this->setId($notification, 101);

        $service->publish($notification);

        self::assertNotNull($request);
        self::assertSame('POST', $request[0]);
        self::assertSame('https://rest.ably.io/channels/gei-user-7/messages', $request[1]);
        self::assertContains('Authorization: Basic ' . base64_encode('app.key:secret'), $request[2]['headers']);
        self::assertSame([[
            'name' => 'notification.created',
            'data' => ['id' => 101],
        ]], json_decode($request[2]['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    private function persistedUser(int $id): User
    {
        $user = new User();
        $this->setId($user, $id);

        return $user;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
