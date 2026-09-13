<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\AlertTransmission;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\AlertStatut;
use App\Enum\UserRoleEnum;
use App\Voter\AlertVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Tests unitaires pour le module Transmissions (Spec Partie E) :
 * - E.1 : Bouton / Action Transmettre réservé strictement au Manager (PFT), interdit aux Agents
 * - E.2 : Cycle de vie de la transmission (en_cours -> cloture) et horodatage
 */
class TransmissionWorkflowTest extends TestCase
{
    /**
     * Test E.1 : Vérification que l'Agent (EMETTEUR_TERRAIN) ne peut JAMAIS transmettre,
     * même sur sa propre alerte validée, alors que le Manager (PFT) le peut.
     */
    public function testDroitsTransmissionManagerVsAgent(): void
    {
        $market = new Market();
        $market->setCodeIso3('BEN');
        $market->setNom('Bénin');

        // Création de l'Agent
        $agent = new User();
        $agent->setEmail('agent@gei.org');
        $agent->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        $agent->setMarket($market);

        // Création du Manager (PFT)
        $manager = new User();
        $manager->setEmail('manager@gei.org');
        $manager->setRole(UserRoleEnum::PFT);
        $manager->setMarket($market);

        // Alerte validée avec score
        $alert = new Alert();
        $alert->setMarket($market);
        $alert->setEmetteur($agent);
        $alert->setStatut(AlertStatut::VALIDEE);
        $alert->setScoreGei(16);

        $voter = new AlertVoter();

        // 1. Vote pour l'Agent -> ACCESS_DENIED
        $tokenAgent = new UsernamePasswordToken($agent, 'main', $agent->getRoles());
        $voteAgent = $voter->vote($tokenAgent, $alert, [AlertVoter::TRANSMIT]);
        $this->assertEquals(
            VoterInterface::ACCESS_DENIED,
            $voteAgent,
            'L\'Agent ne doit JAMAIS avoir le droit de transmettre une alerte (Spec E.1)'
        );

        // 2. Vote pour le Manager -> ACCESS_GRANTED
        $tokenManager = new UsernamePasswordToken($manager, 'main', $manager->getRoles());
        $voteManager = $voter->vote($tokenManager, $alert, [AlertVoter::TRANSMIT]);
        $this->assertEquals(
            VoterInterface::ACCESS_GRANTED,
            $voteManager,
            'Le Manager responsable du marché doit avoir le droit de transmettre'
        );
    }

    /**
     * Test E.2 : Statut de la transmission initialement "en_cours" (En cours de résolution)
     * puis passage à "cloture" (Clôturé) avec horodatage dateCloture.
     */
    public function testCycleDeVieTransmission(): void
    {
        $alert = new Alert();
        $alert->setResumeExecutif('Alerte de fraude douanière');

        $manager = new User();
        $manager->setPrenom('Amadou');
        $manager->setNom('Diallo');
        $manager->setRole(UserRoleEnum::PFT);

        $transmission = new AlertTransmission();
        $transmission->setAlert($alert);
        $transmission->setDestinataire('Direction Générale des Douanes');
        $transmission->setNoteTransmission('Dossier prioritaire transmis pour inspection');
        $transmission->setTransmisBy($manager);

        // Simuler onPrePersist
        $transmission->onPrePersist();

        // 1. Vérification état initial
        $this->assertEquals(AlertTransmission::STATUT_EN_COURS, $transmission->getStatut());
        $this->assertEquals('En cours de résolution', $transmission->getStatutLabel());
        $this->assertTrue($transmission->isEnCours());
        $this->assertFalse($transmission->isCloture());
        $this->assertNull($transmission->getDateCloture());
        $this->assertNotNull($transmission->getTransmisLe());
        $this->assertSame($manager, $transmission->getManager());

        // 2. Clôture par le Manager
        $transmission->cloturer();

        // 3. Vérification état clôturé
        $this->assertEquals(AlertTransmission::STATUT_CLOTURE, $transmission->getStatut());
        $this->assertEquals('Clôturé', $transmission->getStatutLabel());
        $this->assertTrue($transmission->isCloture());
        $this->assertFalse($transmission->isEnCours());
        $this->assertInstanceOf(\DateTime::class, $transmission->getDateCloture());
    }
}
