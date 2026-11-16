<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Gère l'enregistrement et l'authentification WebAuthn (empreinte digitale / passkeys).
 *
 * Le flux s'appuie sur la W3C WebAuthn API :
 *  - enregistrement : create() -> attestation -> CredentialRecord stocké
 *  - authentification : get() -> assertion -> validation de la signature
 *
 * Les credentials sont créés avec residentKey=required pour permettre l'authentification
 * sans mot de passe (détection de la clé par l'empreinte).
 */
final class WebauthnService
{
    private const SESSION_REGISTER_CHALLENGE = 'webauthn.register.challenge';
    private const SESSION_AUTH_CHALLENGE = 'webauthn.auth.challenge';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebauthnCredentialRepository $webauthnCredentialRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getRpId(string $fallback = 'localhost'): string
    {
        $rpId = $_ENV['WEBAUTHN_RP_ID'] ?? $_SERVER['WEBAUTHN_RP_ID'] ?? null;
        if (null !== $rpId && '' !== $rpId) {
            return $rpId;
        }

        return $fallback;
    }

    public function getRpName(): string
    {
        return $_ENV['WEBAUTHN_RP_NAME'] ?? $_SERVER['WEBAUTHN_RP_NAME'] ?? 'GEI System';
    }

    public function getOrigin(Request $request): string
    {
        $origin = $_ENV['WEBAUTHN_ORIGIN'] ?? $_SERVER['WEBAUTHN_ORIGIN'] ?? null;
        if (null !== $origin && '' !== $origin) {
            return $origin;
        }

        return $request->getSchemeAndHttpHost();
    }

    /**
     * Génère les options d'enregistrement WebAuthn à envoyer au navigateur.
     *
     * @return array{options: array<string, mixed>, challenge: string} challenge encodé en base64url
     */
    public function createRegistrationOptions(User $user, Request $request): array
    {
        $challenge = random_bytes(32);
        $request->getSession()->set(self::SESSION_REGISTER_CHALLENGE.'.'.$user->getId(), $challenge);

        $rpId = $this->getRpId(parse_url($request->getSchemeAndHttpHost(), PHP_URL_HOST) ?? 'localhost');

        $excludeCredentials = [];
        foreach ($user->getWebauthnCredentials() as $credential) {
            $excludeCredentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $this->base64urlDecode($credential->getCredentialId()),
            );
        }

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->getRpName(), $rpId),
            PublicKeyCredentialUserEntity::create(
                $user->getEmail(),
                $this->base64urlEncode((string) $user->getId()),
                $user->getNomComplet(),
            ),
            $challenge,
            [
                PublicKeyCredentialParameters::createPk(-7),  // ES256
                PublicKeyCredentialParameters::createPk(-257), // RS256
            ],
            \Webauthn\AuthenticatorSelectionCriteria::create(
                \Webauthn\AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                \Webauthn\AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                \Webauthn\AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            'none',
            $excludeCredentials,
            300_000,
        );

        $serialized = $this->serializeCreationOptions($options);

        $this->logger->info('WebAuthn registration options generated', [
            'user_id' => $user->getId(),
            'origin' => $this->getOrigin($request),
            'rp_id' => $rpId,
        ]);

        return [
            'options' => $serialized,
            'challenge' => $this->base64urlEncode($challenge),
        ];
    }

    /**
     * Valide une réponse d'enregistrement et retourne le CredentialRecord à persister.
     *
     * @param array<string, mixed> $response
     */
    public function completeRegistration(array $response, User $user, Request $request): CredentialRecord
    {
        $userId = $user->getId();
        $key = self::SESSION_REGISTER_CHALLENGE.'.'.$userId;
        $challenge = (string) $request->getSession()->get($key, '');
        if ('' === $challenge) {
            throw new \RuntimeException('Défi d’enregistrement WebAuthn introuvable (session expirée ?).');
        }

        $rpId = $this->getRpId(parse_url($request->getSchemeAndHttpHost(), PHP_URL_HOST) ?? 'localhost');

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->getRpName(), $rpId),
            PublicKeyCredentialUserEntity::create(
                $user->getEmail(),
                $this->base64urlEncode((string) $user->getId()),
                $user->getNomComplet(),
            ),
            $challenge,
            [],
            null,
            'none',
        );

        $attestationResponse = $this->denormalizeAttestationResponse($response);
        $host = $this->getOrigin($request);

        $validator = AuthenticatorAttestationResponseValidator::create(
            $this->createCeremonyManager($request, 'registration')
        );

        $credentialRecord = $validator->check($attestationResponse, $options, $host);

        $request->getSession()->remove($key);

        $this->logger->info('WebAuthn registration completed', [
            'user_id' => $userId,
            'credential_id' => $this->base64urlEncode($credentialRecord->publicKeyCredentialId),
        ]);

        return $credentialRecord;
    }

    /**
     * Génère les options d'authentification WebAuthn (empreinte digitale, sans mot de passe).
     * allowCredentials vide = détection automatique des clés résidentes de l'utilisateur.
     *
     * @return array{options: array<string, mixed>, challenge: string} challenge encodé en base64url
     */
    public function createAuthenticationOptions(Request $request): array
    {
        $challenge = random_bytes(32);
        $request->getSession()->set(self::SESSION_AUTH_CHALLENGE, $challenge);

        $rpId = $this->getRpId(parse_url($request->getSchemeAndHttpHost(), PHP_URL_HOST) ?? 'localhost');

        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $rpId,
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            300_000,
        );

        $serialized = $this->serializeRequestOptions($options);

        $this->logger->debug('WebAuthn authentication options generated', [
            'origin' => $this->getOrigin($request),
            'rp_id' => $rpId,
        ]);

        return [
            'options' => $serialized,
            'challenge' => $this->base64urlEncode($challenge),
        ];
    }

    /**
     * Valide une réponse d'authentification et retourne l'utilisateur authentifié.
     *
     * @param array<string, mixed> $response
     */
    public function completeAuthentication(array $response, Request $request): User
    {
        $challenge = (string) $request->getSession()->get(self::SESSION_AUTH_CHALLENGE, '');
        if ('' === $challenge) {
            throw new \RuntimeException('Défi d’authentification WebAuthn introuvable (session expirée ?).');
        }

        $credentialIdB64 = (string) ($response['id'] ?? '');
        if ('' === $credentialIdB64) {
            throw new \RuntimeException('Identifiant de credential WebAuthn manquant.');
        }

        $credential = $this->webauthnCredentialRepository->findOneByCredentialId($credentialIdB64);
        if (null === $credential) {
            $this->logger->warning('WebAuthn authentication with unknown credential', [
                'credential_id' => $credentialIdB64,
            ]);
            throw new \RuntimeException('Credential WebAuthn inconnu.');
        }

        $user = $credential->getUser();
        $rpId = $this->getRpId(parse_url($request->getSchemeAndHttpHost(), PHP_URL_HOST) ?? 'localhost');

        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $rpId,
            [
                PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $this->base64urlDecode($credential->getCredentialId()),
                    $credential->getTransports(),
                ),
            ],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            300_000,
        );

        $assertionResponse = $this->denormalizeAssertionResponse($response);
        $host = $this->getOrigin($request);

        $validator = AuthenticatorAssertionResponseValidator::create(
            $this->createCeremonyManager($request, 'authentication')
        );

        $validator->check(
            $this->buildCredentialRecord($credential),
            $assertionResponse,
            $options,
            $host,
            $credential->getUserHandle(),
        );

        $request->getSession()->remove(self::SESSION_AUTH_CHALLENGE);

        $credential->setSignCount($assertionResponse->authenticatorData->signCount);
        $credential->touch();
        $this->entityManager->persist($credential);
        $this->entityManager->flush();

        $this->logger->info('WebAuthn authentication successful', [
            'user_id' => $user->getId(),
            'credential_id' => $credentialIdB64,
        ]);

        return $user;
    }

    /**
     * Transforme une entité WebauthnCredential en CredentialRecord utilisable par le validateur.
     */
    public function buildCredentialRecord(WebauthnCredential $credential): CredentialRecord
    {
        $aaguid = $credential->getAaguid();
        if (null === $aaguid || '' === $aaguid) {
            $aaguid = '00000000-0000-0000-0000-000000000000';
        }

        return CredentialRecord::create(
            $this->base64urlDecode($credential->getCredentialId()),
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $credential->getTransports(),
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString($aaguid),
            $this->base64urlDecode($credential->getPublicKey()),
            $this->base64urlDecode($credential->getUserHandle()),
            $credential->getSignCount(),
        );
    }

    /**
     * Persiste un CredentialRecord fraîchement validé en entité WebauthnCredential.
     */
    public function persistCredentialRecord(User $user, CredentialRecord $record, string $name): WebauthnCredential
    {
        $credential = new WebauthnCredential();
        $credential->setUser($user);
        $credential->setCredentialId($this->base64urlEncode($record->publicKeyCredentialId));
        $credential->setPublicKey($this->base64urlEncode($record->credentialPublicKey));
        $credential->setTransports($record->transports);
        $credential->setSignCount($record->counter);
        $credential->setAaguid($record->aaguid->toString());
        $credential->setUserHandle($this->base64urlEncode($record->userHandle));
        $credential->setName($name);

        $this->entityManager->persist($credential);
        $this->entityManager->flush();

        return $credential;
    }

    /**
     * Supprime une clé WebAuthn d'un utilisateur.
     */
    public function removeCredential(WebauthnCredential $credential): void
    {
        $this->entityManager->remove($credential);
        $this->entityManager->flush();

        $this->logger->info('WebAuthn credential removed', [
            'user_id' => $credential->getUser()->getId(),
            'credential_id' => $credential->getCredentialId(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createCeremonyManager(Request $request, string $type): \Webauthn\CeremonyStep\CeremonyStepManager
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAttestationStatementSupportManager(new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]));

        $origin = $this->getOrigin($request);
        $rpId = $this->getRpId(parse_url($origin, PHP_URL_HOST) ?? 'localhost');

        $factory->setAllowedOrigins([$origin], false);

        return 'registration' === $type
            ? $factory->creationCeremony()
            : $factory->requestCeremony();
    }

    /**
     * @param mixed[] $response
     */
    private function denormalizeAttestationResponse(array $response): AuthenticatorAttestationResponse
    {
        /** @var Serializer $serializer */
        $serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()])
        ))->create();

        $data = [
            'clientDataJSON' => $response['clientDataJSON'] ?? '',
            'attestationObject' => $response['attestationObject'] ?? '',
            'transports' => $response['transports'] ?? [],
        ];

        /** @var AuthenticatorAttestationResponse $result */
        $result = $serializer->denormalize($data, AuthenticatorAttestationResponse::class, 'json');

        return $result;
    }

    /**
     * @param mixed[] $response
     */
    private function denormalizeAssertionResponse(array $response): AuthenticatorAssertionResponse
    {
        /** @var Serializer $serializer */
        $serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()])
        ))->create();

        $data = [
            'authenticatorData' => $response['authenticatorData'] ?? '',
            'signature' => $response['signature'] ?? '',
            'clientDataJSON' => $response['clientDataJSON'] ?? '',
            'userHandle' => $response['userHandle'] ?? null,
        ];

        if (isset($response['attestationObject'])) {
            $data['attestationObject'] = $response['attestationObject'];
        }

        /** @var AuthenticatorAssertionResponse $result */
        $result = $serializer->denormalize($data, AuthenticatorAssertionResponse::class, 'json');

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCreationOptions(PublicKeyCredentialCreationOptions $options): array
    {
        /** @var Serializer $serializer */
        $serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()])
        ))->create();

        /** @var array<string, mixed> $data */
        $data = $serializer->normalize($options, 'json');

        // Le challenge (octets bruts) est encodé en base64url pour le transport vers le navigateur
        if (isset($data['challenge']) && is_string($data['challenge'])) {
            $data['challenge'] = $this->base64urlEncode($data['challenge']);
        }

        // Les identifiants de credential sont en octets bruts : base64url pour le navigateur
        foreach (['allowCredentials', 'excludeCredentials'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            foreach ($data[$key] as &$descriptor) {
                if (is_array($descriptor) && isset($descriptor['id']) && is_string($descriptor['id'])) {
                    $descriptor['id'] = $this->base64urlEncode($descriptor['id']);
                }
            }
            unset($descriptor);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRequestOptions(PublicKeyCredentialRequestOptions $options): array
    {
        /** @var Serializer $serializer */
        $serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()])
        ))->create();

        /** @var array<string, mixed> $data */
        $data = $serializer->normalize($options, 'json');

        if (isset($data['challenge']) && is_string($data['challenge'])) {
            $data['challenge'] = $this->base64urlEncode($data['challenge']);
        }

        if (isset($data['allowCredentials']) && is_array($data['allowCredentials'])) {
            foreach ($data['allowCredentials'] as &$descriptor) {
                if (is_array($descriptor) && isset($descriptor['id']) && is_string($descriptor['id'])) {
                    $descriptor['id'] = $this->base64urlEncode($descriptor['id']);
                }
            }
            unset($descriptor);
        }

        return $data;
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Données base64url invalides.');
        }

        return $decoded;
    }
}
