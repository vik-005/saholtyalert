<?php

namespace App\Repository;

use App\Entity\ImportBatch;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportBatch>
 */
class ImportBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportBatch::class);
    }

    public function save(ImportBatch $batch): void
    {
        $this->getEntityManager()->persist($batch);
        $this->getEntityManager()->flush();
    }

    public function findOneByBatchId(string $batchId): ?ImportBatch
    {
        return $this->createQueryBuilder('ib')
            ->where('ib.batchId = :batchId')
            ->setParameter('batchId', $batchId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findRecent(int $days = 7): array
    {
        return $this->createQueryBuilder('ib')
            ->where('ib.createdAt >= :date')
            ->setParameter('date', new \DateTimeImmutable("-{$days} days"))
            ->orderBy('ib.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPending(): array
    {
        return $this->createQueryBuilder('ib')
            ->where('ib.status = :status')
            ->setParameter('status', ImportBatch::STATUS_PENDING)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('ib')
            ->select('ib.status, COUNT(ib.id) as cnt')
            ->groupBy('ib.status')
            ->getQuery()
            ->getArrayResult();

        $result = [
            ImportBatch::STATUS_PENDING => 0,
            ImportBatch::STATUS_PROCESSING => 0,
            ImportBatch::STATUS_SUCCESS => 0,
            ImportBatch::STATUS_ERROR => 0,
        ];

        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }

        return $result;
    }
}
