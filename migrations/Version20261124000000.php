<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Widgets (clock, weather, ticker) per screen and per group. Schema API only. */
final class Version20261124000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widget settings on screens and screen groups';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('screen')->addColumn('overlays', 'json', ['notnull' => false]);
        $schema->getTable('screen_group')->addColumn('overlays', 'json', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('screen')->dropColumn('overlays');
        $schema->getTable('screen_group')->dropColumn('overlays');
    }
}
