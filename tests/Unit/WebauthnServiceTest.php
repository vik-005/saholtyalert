<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\WebauthnCredentialRepository;
use App\Service\WebauthnService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebauthnServiceTest extends TestCase
{
    public function testAuthenticationOptionsPreserveTheOriginalChallengeEncoding(): void
    {
        $request = Request::create('https://gei.example.org/login/webauthn/options');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $result = $this->createWebauthnService()->createAuthenticationOptions($request);
        $challenge = $session->get('webauthn.auth.challenge');
        $encodedChallenge = $result['options']['challenge'];

        self::assertIsString($challenge);
        self::assertIsString($encodedChallenge);
        self::assertSame($challenge, $this->decodeBase64Url($encodedChallenge));
    }

    public function testAuthenticationOptionsPreserveCredentialIdEncoding(): void
    {
        $credentialId = "\x01\x02\x03\xfe";
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            'gei.example.org',
            [PublicKeyCredentialDescriptor::create('public-key', $credentialId)],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            300_000,
        );
        $serializer = new \ReflectionMethod(WebauthnService::class, 'serializeRequestOptions');

        $result = $serializer->invoke($this->createWebauthnService(), $options);

        self::assertSame(
            $this->encodeBase64Url($credentialId),
            $result['allowCredentials'][0]['id'],
        );
    }

    public function testRegistrationOptionsPreserveChallengeAndExcludedCredentialEncoding(): void
    {
        $challenge = random_bytes(32);
        $credentialId = "\x01\x02\x03\xfe";
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('GEI', 'gei.example.org'),
            PublicKeyCredentialUserEntity::create('user@example.org', 'MQ', 'Test User'),
            $challenge,
            [PublicKeyCredentialParameters::createPk(-7)],
            null,
            'none',
            [PublicKeyCredentialDescriptor::create('public-key', $credentialId)],
            300_000,
        );
        $serializer = new \ReflectionMethod(WebauthnService::class, 'serializeCreationOptions');

        $result = $serializer->invoke($this->createWebauthnService(), $options);

        self::assertSame($this->encodeBase64Url($challenge), $result['challenge']);
        self::assertSame(
            $this->encodeBase64Url($credentialId),
            $result['excludeCredentials'][0]['id'],
        );
    }

    private function createWebauthnService(): WebauthnService
    {
        return new WebauthnService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(WebauthnCredentialRepository::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function encodeBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);

        return base64_decode($value, true);
    }
}
