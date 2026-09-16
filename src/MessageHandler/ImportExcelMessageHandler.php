<?php

namespace App\MessageHandler;

use App\Entity\ActivityLog;
use App\Entity\ImportBatch;
use App\Entity\User;
use App\Message\ImportExcelMessage;
use App\Repository\ActivityLogRepository;
use App\Repository\ImportBatchRepository;
use App\Repository\UserRepository;
use App\Service\ImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Handler pour l'import Excel asynchrone via Messenger.
 *
 * Traite les fichiers volumineux (>500 lignes) sans bloquer la requête HTTP.
 */
#[AsMessageHandler]
class ImportExcelMessageHandler
{
    private const MAX_LINES_SYNC = 500;
    public function __construct(
        private readonly ImportService $importService,
        private readonly EntityManagerInterface $em,
        private readonly ImportBatchRepository $batchRepo,
        private readonly UserRepository $userRepo,
        private readonly ActivityLogRepository $activityRepo,
        private readonly CacheInterface $cache,
        private readonly ManagerRegistry $registry,
    ) {}

    public function __invoke(ImportExcelMessage $message): void
    {
        $user = $this->userRepo->find($message->getUserId());
        if (!$user) {
            throw new UnrecoverableMessageHandlingException('User not found: ' . $message->getUserId());
        }

        $filePath = $message->getFilePath();
        if (!file_exists($filePath)) {
            throw new UnrecoverableMessageHandlingException('File not found: ' . $filePath);
        }

        $batchId = $message->getBatchId();
        if ($batchId === null) {
            $batchId = uniqid('import_', true);
        }

        // Récupérer ou créer le batch
        $batch = $this->batchRepo->findOneByBatchId($batchId);
        if (!$batch) {
            $batch = new ImportBatch();
            $batch->setBatchId($batchId);
            $batch->setUser($user);
            $batch->setFilename(basename($filePath));
            $batch->setStatus(ImportBatch::STATUS_PROCESSING);
            $this->batchRepo->save($batch);
        }

        try {
            $batch->setStatus(ImportBatch::STATUS_PROCESSING);
            $batch->setLinesTotal($this->importService->countDataRows($filePath));
            $this->batchRepo->save($batch);
            $result = $this->importService->importPath($filePath, $user, $batchId);

            // Le traitement par lots peut avoir détaché le batch et l'utilisateur.
            $this->registry->resetManager();
            $freshEm = $this->registry->getManager();
            $freshBatch = $freshEm->getRepository(ImportBatch::class)->findOneBy(['batchId' => $batchId]);
            $freshUser = $freshEm->getRepository(User::class)->find($message->getUserId());
            if (!$freshBatch instanceof ImportBatch || !$freshUser instanceof User) {
                throw new \RuntimeException('Impossible de recharger le contexte de suivi de l’import.');
            }

            // Mettre à jour le batch
            $freshBatch->updateStatus(
                ImportBatch::STATUS_SUCCESS,
                [
                    'success_count' => $result['success_count'] ?? 0,
                    'update_count' => $result['update_count'] ?? 0,
                    'error_count' => $result['error_count'] ?? 0,
                    'lignes_incompletes_count' => count($result['lignes_incompletes'] ?? []),
                ],
                $result['errors'] ?? []
            );
            $freshEm->flush();

            // Log d'activité
            $freshEm->persist(ActivityLog::log(
                $freshUser,
                'import_excel',
                (string) $freshBatch->getId(),
                sprintf('Import %s terminé : %d créées, %d mises à jour.', basename($filePath), $result['success_count'] ?? 0, $result['update_count'] ?? 0),
                $result
            ));
            $freshEm->flush();

            // Invalider le cache KPI
            if ($this->cache instanceof TagAwareCacheInterface) {
                $this->cache->invalidateTags(['kpi_alertes']);
            }

        } catch (\Throwable $e) {
            // Doctrine ferme l'EntityManager après une erreur SQL de flush.
            // Réinitialiser le manager avant d'enregistrer le statut d'échec,
            // sinon le message utile est remplacé par "The EntityManager is closed".
            $this->registry->resetManager();
            $freshEm = $this->registry->getManager();
            $freshBatch = $freshEm->getRepository(ImportBatch::class)->findOneBy(['batchId' => $batchId]);
            if ($freshBatch instanceof ImportBatch) {
                $freshBatch->updateStatus(
                    ImportBatch::STATUS_ERROR,
                    null,
                    ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                );
                $freshEm->flush();
            }

            throw $e;
        } finally {
            // Nettoyage du fichier temporaire
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

}
