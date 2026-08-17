<?php

namespace App\DataFixtures;

use App\Entity\Alert;
use App\Entity\Market;
use App\Entity\RuleConfig;
use App\Entity\User;
use App\Enum\AlertCategorie;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\AnonymisationNiveau;
use App\Enum\FiabiliteSource;
use App\Enum\Sensibilite;
use App\Enum\TransmissionStatut;
use App\Enum\TypeSource;
use App\Enum\UserRoleEnum;
use App\Repository\RuleConfigRepository;
use App\Service\AlertCodeGeneratorService;
use App\Service\ScoreCalculatorService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures de développement/test — jeu de données représentatif du registre GEI réel.
 *
 * Ordre de chargement critique :
 *   1. RuleConfig (seuils scoring — AVANT les alertes)
 *   2. Markets
 *   3. Users
 *   4. Alertes avec score calculé
 *
 * ScoreCalculatorService dépend de RuleConfigRepository donc les RuleConfig
 * doivent être persistés ET flushés avant de calculer les scores.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RuleConfigRepository       $ruleConfigRepo,
        private readonly AlertCodeGeneratorService  $codeGen,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // ── ÉTAPE 1 : RuleConfig (seuils scoring Annexe C) ─────────────────────
        // Persistés ET flushés en premier — ScoreCalculatorService les lira à l'étape 4.
        $rulesData = [
            ['score_critique',   '18', 'Seuil minimum pour statut Critique et activation Urgence 72h (Annexe C)',   'scoring'],
            ['score_eleve_min',  '14', 'Seuil minimum pour priorité Élevée (Annexe C)',                            'scoring'],
            ['score_modere_min', '10', 'Seuil minimum pour priorité Modérée (Annexe C)',                           'scoring'],
            ['sla_72h_heures',   '72', 'Délai maximal SLA procédure d\'urgence critique (heures)',                 'sla'],
            ['sla_analyse_rapide_heures', '6', 'Délai tâche analyse rapide — règle 3 escalade (heures)',           'sla'],
        ];

        foreach ($rulesData as [$cle, $valeur, $desc, $categorie]) {
            $r = new RuleConfig();
            $r->setCle($cle);
            $r->setValeur($valeur);
            $r->setDescription($desc);
            $r->setCategorie($categorie);
            $manager->persist($r);
        }

        $manager->flush(); // flush RuleConfig d'abord — OBLIGATOIRE avant calcul des scores

        // ── ÉTAPE 2 : Markets ───────────────────────────────────────────────────
        $marketsData = [
            ['BEN', 'Bénin'],
            ['TGO', 'Togo'],
            ['GHA', 'Ghana'],
            ['CIV', 'Côte d\'Ivoire'],
            ['MLI', 'Mali'],
            ['NER', 'Niger'],
            ['SEN', 'Sénégal'],
            ['GIN', 'Guinée'],
            ['AGO', 'Angola'],
            ['CMR', 'Cameroun'],
            ['COD', 'RDC'],
            ['OTH', 'Autre Zone'],
        ];

        $markets = [];
        foreach ($marketsData as [$iso, $nom]) {
            $m = new Market();
            $m->setCodeIso3($iso);
            $m->setNom($nom);
            $manager->persist($m);
            $markets[$iso] = $m;
        }

        $manager->flush();

        // ── ÉTAPE 3 : Users ─────────────────────────────────────────────────────
        $usersData = [
            // email,                     pass,          prénom,          nom,           rôle,                         market
            ['admin@gei.org',         'Admin@2026!',  'Marc',          'KOUAME',       UserRoleEnum::SUPERADMIN,       null],
            ['admin2@gei.org',        'Admin@2026!',  'Sarah',         'KOFFI',        UserRoleEnum::SUPERADMIN,       null],
            ['secretariat@gei.org',   'Secr@2026!',   'Paul',          'HOUNGBO',      UserRoleEnum::SECRETARIAT_GEI,  null],
            ['secretariat2@gei.org',  'Secr@2026!',   'Claire',        'MENSAH',       UserRoleEnum::SECRETARIAT_GEI,  null],
            ['saholty@gei.org',       'Saho@2026!',   'Dr. Eric',      'AGBOTA',       UserRoleEnum::SAHOLTY,          null],
            ['saholty2@gei.org',      'Saho@2026!',   'Aicha',         'DIALLO',       UserRoleEnum::SAHOLTY,          null],
            ['pft.benin@gei.org',     'Pft@2026!',    'Jean',          'DOSSOU',       UserRoleEnum::PFT,              $markets['BEN']],
            ['pft.togo@gei.org',      'Pft@2026!',    'Kossi',         'LAWSON',       UserRoleEnum::PFT,              $markets['TGO']],
            ['emetteur.benin@gei.org','Emet@2026!',   'Alain',         'TCHIBOZO',     UserRoleEnum::EMETTEUR_TERRAIN, $markets['BEN']],
            ['emetteur.ghana@gei.org','Emet@2026!',   'Kwame',         'OWUSU',        UserRoleEnum::EMETTEUR_TERRAIN, $markets['GHA']],
            ['comite@gei.org',        'Com@2026!',    'Général Michel','ADANHOUNME',   UserRoleEnum::COMITE_AIT,       null],
            ['comite2@gei.org',       'Com@2026!',    'Col. Ibrahim',  'SORY',         UserRoleEnum::COMITE_AIT,       null],
        ];

        $users = [];
        foreach ($usersData as [$email, $pass, $prenom, $nom, $role, $market]) {
            $u = new User();
            $u->setEmail($email);
            $u->setPrenom($prenom);
            $u->setNom($nom);
            $u->setRole($role);
            $u->setMarket($market);
            $u->setPassword($this->hasher->hashPassword($u, $pass));
            $manager->persist($u);
            $users[$email] = $u;
        }

        // PFT Bénin gère aussi Togo en secondaire (exemple de multi-marché)
        $users['pft.benin@gei.org']->addMarket($markets['BEN']);
        $users['pft.benin@gei.org']->addMarket($markets['TGO']);

        $manager->flush();

        // ── ÉTAPE 4 : Alertes représentatives ───────────────────────────────────
        // ScoreCalculatorService est instancié ici (après flush des RuleConfig)
        $scoreCalculator = new ScoreCalculatorService($this->ruleConfigRepo);

        $alertesData = [
            // [iso, emetteur, corridor, catégorie, résumé, fiab, cred, urg, imp, exp, faits, hypothèses, statut]
            [
                'BEN', 'emetteur.benin@gei.org',
                'Corridor Cotonou - Niamey',
                AlertCategorie::CONTAINER_SUSPECT,
                'Soupçon de transit illicite de conteneurs de tabac déclarés comme carrelage.',
                FiabiliteSource::A, 1, AlertUrgence::IMMEDIAT, AlertImpact::ELEVE, AlertExploitabilite::ACTIONNABLE,
                'Conteneur MSKU9283741 arrivé au Port de Cotonou le 10/08/2026. Manifeste indiquant du carrelage mais scan RX montrant une densité organique type cartons.',
                'Le réseau utiliserait la déclaration carrelage pour contourner les droits douaniers vers le Niger.',
                AlertStatut::EN_COURS,
            ],
            [
                'TGO', 'pft.togo@gei.org',
                'Port de Lomé — Môle 2',
                AlertCategorie::TRANSIT_TABAC,
                'Cargaison suspecte de marque ESSE EDGE en provenance de Dubaï via Tanger.',
                FiabiliteSource::B, 2, AlertUrgence::SOIXANTE_DOUZE_H, AlertImpact::ELEVE, AlertExploitabilite::ACTIONNABLE,
                'Interception de 500 cartons au môle 2. Documents falsifiés au nom de la société AVENTUS SARL.',
                'Tentative de ré-exportation informelle vers le marché de l\'hinterland (Mali).',
                AlertStatut::TRANSMIS,
            ],
            [
                'GHA', 'emetteur.ghana@gei.org',
                'Poste Frontière de Widana',
                AlertCategorie::FLUX_TERRESTRE,
                'Mouvement nocturne inhabituel de camions de transit non déclarés.',
                FiabiliteSource::C, 3, AlertUrgence::ROUTINE, AlertImpact::MOYEN, AlertExploitabilite::A_COMPLETER,
                'Observation terrain de 3 camions benne franchissant la frontière après 23h.',
                'Suspicion de complicité locale pour franchissement nocturne.',
                AlertStatut::A_INVESTIGUER,
            ],
            [
                'CIV', 'emetteur.benin@gei.org',
                'Abidjan — Zone portuaire',
                AlertCategorie::ACTEUR,
                'Repérage d\'un intermédiaire connu impliqué dans des réseaux de fausse déclaration.',
                FiabiliteSource::B, 1, AlertUrgence::SOIXANTE_DOUZE_H, AlertImpact::ELEVE, AlertExploitabilite::ACTIONNABLE,
                'L\'individu A.K. (alias "le courtier") a été identifié comme transitaire pour 3 importateurs suspects.',
                'Probable rôle de coordinateur pour l\'acheminement de tabac illicite entre Abidjan et Bamako.',
                AlertStatut::A_VALIDER_SAHOLTY,
            ],
            [
                'BEN', 'emetteur.benin@gei.org',
                'Corridor Cotonou - Parakou',
                AlertCategorie::SAISIE,
                'Saisie de 200 cartons de cigarettes de marque non homologuée.',
                FiabiliteSource::A, 1, AlertUrgence::IMMEDIAT, AlertImpact::ELEVE, AlertExploitabilite::ACTIONNABLE,
                'Saisie opérée le 05/08/2026 au PK 45 de la RN2. Marque FINE 100s non présente dans la liste homologuée.',
                'Flux régulier sur cet axe depuis juin 2026 selon les patrouilles.',
                AlertStatut::TRANSMIS,
            ],
        ];

        foreach ($alertesData as [
            $iso, $emetteurEmail, $corridor, $cat, $resume,
            $fiab, $cred, $urg, $imp, $exp,
            $faits, $hyp, $statut
        ]) {
            $alert = new Alert();
            $alert->setMarket($markets[$iso]);
            $alert->setEmetteur($users[$emetteurEmail]);
            $alert->setPortCorridor($corridor);
            $alert->setCategorie($cat);
            $alert->setResumeExecutif($resume);
            $alert->setTypeSource(TypeSource::TERRAIN);
            $alert->setAnonymisation(AnonymisationNiveau::ELEVE);
            $alert->setSensibilite(Sensibilite::RESTREINTE);
            $alert->setElementsFactuels($faits);
            $alert->setHypothesesAnalytiques($hyp);
            $alert->setStatut($statut);
            $alert->setTransmission(
                in_array($statut, [AlertStatut::TRANSMIS], true)
                    ? TransmissionStatut::OUI
                    : TransmissionStatut::NON
            );

            // Critères de scoring (sections 3 + 6)
            $alert->setFiabiliteSource($fiab);
            $alert->setCredibiliteContenu($cred);
            $alert->setUrgence($urg);
            $alert->setImpact($imp);
            $alert->setExploitabilite($exp);

            // Code GEI généré selon le format exact GEI-{ISO3}-{ANNEE}-{SEQ:003}
            $alert->setCodeGei($this->codeGen->generate($alert));

            // Score calculé automatiquement — JAMAIS saisi manuellement
            $scoreCalculator->calculate($alert);

            $manager->persist($alert);
        }

        $manager->flush();
    }
}
