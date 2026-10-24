<?php

namespace App\Command;

use App\Entity\Alert;
use App\Entity\AlertMarket;
use App\Entity\Market;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-parcours',
    description: 'Analyse et synchronise les parcours transfrontaliers à partir des corridors d\'alertes'
)]
class SyncParcoursMarketsCommand extends Command
{
    /** Mots-clés associés aux marchés pour détecter les transits et étapes */
    private const MARKET_KEYWORDS = [
        'NER' => ['niger', 'niamey', 'zinder', 'maradi', 'dosso', 'agadez'],
        'BFA' => ['burkina', 'ouagadougou', 'kulungugu', 'bobo-dioulasso', 'koupela'],
        'CMR' => ['cameroun', 'cameroon', 'douala', 'yaounde', 'kribi'],
        'SEN' => ['senegal', 'sénégal', 'dakar', 'diass', 'kaolack'],
        'CIV' => ["cote d'ivoire", "côte d'ivoire", 'abidjan', 'san pedro', 'ouangolodougou'],
        'MLI' => ['mali', 'bamako', 'sikasso', 'kayes'],
        'TGO' => ['togo', 'lome', 'lomé', 'cinkasse', 'cinkassé'],
        'BEN' => ['benin', 'bénin', 'cotonou', 'seme', 'sèmè', 'malanville', 'parakou', 'hilla condji'],
        'GHA' => ['ghana', 'tema', 'accra', 'takoradi', 'osei kojokrom', 'pulmakol', 'widana', 'hamile'],
        'GIN' => ['guinee', 'guinée', 'conakry', 'kankan'],
        'COD' => ['rdc', 'congo', 'pointe-noire', 'kinshasa', 'lubumbashi'],
        'AGO' => ['angola', 'luanda', 'lobito'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synchronisation des parcours transfrontaliers GEI');

        $marketRepo = $this->em->getRepository(Market::class);
        $alertRepo = $this->em->getRepository(Alert::class);

        $markets = $marketRepo->findAll();
        $marketsByIso = [];
        foreach ($markets as $m) {
            $marketsByIso[$m->getCodeIso3()] = $m;
        }

        $alerts = $alertRepo->createQueryBuilder('a')
            ->where('a.deletedAt IS NULL')
            ->andWhere("a.portCorridor IS NOT NULL AND a.portCorridor != ''")
            ->getQuery()
            ->getResult();

        $syncedCount = 0;
        $totalAssociations = 0;

        foreach ($alerts as $alert) {
            $corridor = (string) $alert->getPortCorridor();
            $principal = $alert->getMarket();
            $principalIso = $principal?->getCodeIso3();

            $detectedIsos = $this->detectMarketsInCorridor($corridor, $principalIso);
            if (empty($detectedIsos)) {
                continue;
            }

            // Récupérer les associations existantes
            $existing = [];
            foreach ($alert->getAlertMarkets() as $am) {
                if ($am->getMarket()) {
                    $existing[$am->getMarket()->getId()] = $am;
                }
            }

            // S'assurer que le principal est enregistré
            if ($principal && !isset($existing[$principal->getId()])) {
                $mainAm = new AlertMarket();
                $mainAm->setAlert($alert);
                $mainAm->setMarket($principal);
                $mainAm->setRole('principal');
                $mainAm->setOrdre(0);
                $this->em->persist($mainAm);
            }

            $order = 1;
            $alertModified = false;
            foreach ($detectedIsos as $iso) {
                $assocMarket = $marketsByIso[$iso] ?? null;
                if (!$assocMarket || ($principal && $assocMarket->getId() === $principal->getId())) {
                    continue;
                }

                if (!isset($existing[$assocMarket->getId()])) {
                    $newAm = new AlertMarket();
                    $newAm->setAlert($alert);
                    $newAm->setMarket($assocMarket);
                    $newAm->setRole('associe');
                    $newAm->setOrdre($order++);
                    $this->em->persist($newAm);
                    $alertModified = true;
                    $totalAssociations++;
                }
            }

            if ($alertModified) {
                $syncedCount++;
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            '%d alertes enrichies avec des étapes transfrontalières (%d nouvelles associations de marchés).',
            $syncedCount,
            $totalAssociations
        ));

        return Command::SUCCESS;
    }

    /**
     * Détecte les marchés mentionnés dans la chaîne du corridor
     * @return string[] Codes ISO3 des marchés détectés dans l'ordre d'apparition
     */
    public function detectMarketsInCorridor(string $corridor, ?string $excludeIso = null): array
    {
        $corridorLower = mb_strtolower($corridor);
        $matches = [];

        foreach (self::MARKET_KEYWORDS as $iso => $keywords) {
            if ($iso === $excludeIso) {
                continue;
            }
            foreach ($keywords as $kw) {
                $pos = mb_strpos($corridorLower, $kw);
                if ($pos !== false) {
                    // Éviter faux positifs (ex: "seme" dans autre mot)
                    $pattern = '/\b' . preg_quote($kw, '/') . '\b/u';
                    if (preg_match($pattern, $corridorLower, $m, PREG_OFFSET_CAPTURE)) {
                        $matches[$iso] = $m[0][1];
                        break;
                    }
                }
            }
        }

        // Trier par ordre d'apparition dans le trajet
        asort($matches);
        return array_keys($matches);
    }
}
