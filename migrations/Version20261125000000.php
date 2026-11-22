<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Interface language per user. Schema API only. */
final class Version20261125000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Interface language chosen by each user';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('app_user')->addColumn('locale', 'string', ['length' => 5, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('app_user')->dropColumn('locale');
    }
}
