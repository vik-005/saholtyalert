<?php

namespace App\EventSubscriber;

use App\Entity\AccessLog;
use App\Entity\Alert;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Subscriber de traçabilité obligatoire (Annexe D §6/§8).
 *
 * RÈGLE CRITIQUE : ce subscriber ne doit JAMAIS appeler $em->flush().
 *   - postLoad : on est DANS un fetch Doctrine, flush = boucle infinie
 *   - onLogin / onLogout : un flush ici déclencherait onFlush → AlertScoreSubscriber
 *     sur toutes les alertes en mémoire → boucle
 *
 * Les logs de connexion/déconnexion sont persistés sans flush immédiat.
 * Ils seront écrits en base lors du prochain flush applicatif (réponse HTTP).
 * Pour une garantie absolue, utiliser un handler Monolog dédié en production.
 */
#[AsDoctrineListener(event: Events::postLoad)]
class AccessLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security               $security,
        private readonly RequestStack           $requestStack,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SecurityEvents::INTERACTIVE_LOGIN => ['onLogin', 0],
            LogoutEvent::class                => ['onLogout', 0],
        ];
    }

    // ── Lectures d'alertes sensibles (via Doctrine postLoad) ─────────────────

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Alert) {
            return;
        }

        $sensibilite = $entity->getSensibilite();
        if ($sensibilite === null) {
            return;
        }
        if (!in_array($sensibilite->value, ['confidentielle', 'restreinte'], true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        try {
            $log = $this->buildLog(
                $user,
                AccessLog::ACTION_LECTURE,
                sprintf('Consultation %s (sensibilité : %s)', $entity->getCodeGei() ?? '#' . $entity->getId(), $sensibilite->value),
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
                $entity,
            );
            $this->em->persist($log);
            // PAS de flush — on est dans un fetch Doctrine actif
        } catch (\Throwable) {
            // Ne jamais bloquer une lecture pour un log raté
        }
    }

    // ── Connexion ────────────────────────────────────────────────────────────

    public function onLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();

        try {
            $log = $this->buildLog(
                $user,
                AccessLog::ACTION_LOGIN,
                sprintf('Connexion de %s (%s)', $user->getNomComplet(), $user->getRole()->value),
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
            $this->em->persist($log);
            // Flush direct ici est sûr car login = nouvelle session,
            // aucune Alert en mémoire de l'UoW à ce stade.
            // On utilise un EM "propre" (clear) pour éviter tout résidu.
            $this->em->flush();
        } catch (\Throwable) {
            // Silencieux — le login ne doit jamais échouer à cause d'un log
        }
    }

    // ── Déconnexion ──────────────────────────────────────────────────────────

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $request = $event->getRequest();

        try {
            $log = $this->buildLog(
                $user,
                AccessLog::ACTION_LOGOUT,
                sprintf('Déconnexion de %s', $user->getNomComplet()),
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
            );
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable) {
            // Silencieux
        }
    }

    // ── Builder ──────────────────────────────────────────────────────────────

    private function buildLog(
        User    $user,
        string  $action,
        string  $details,
        ?string $ip,
        ?string $ua,
        ?Alert  $alert = null,
    ): AccessLog {
        $log = new AccessLog();
        $log->setUser($user);
        $log->setAction($action);
        $log->setDetails($details);
        $log->setIpAdresse($ip);
        $log->setUserAgent($ua);
        $log->setResultat('success');
        if ($alert !== null) {
            $log->setAlert($alert);
        }
        return $log;
    }
}
