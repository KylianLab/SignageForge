<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PairingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PairingSession>
 */
class PairingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PairingSession::class);
    }

    /** Forget pairing sessions that expired more than a day ago. */
    public function purgeStale(?\DateTimeImmutable $now = null): int
    {
        $limit = ($now ?? new \DateTimeImmutable())->modify('-1 day');

        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.expiresAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }
}
