<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Time-of-day / day-of-week schedules per screen. Schema API only, so it runs
 * on SQLite, MariaDB/MySQL and PostgreSQL.
 */
final class Version20261122000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Screen schedules: play a playlist on given days and times';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable('schedule_rule');
        $t->addColumn('id', 'ulid');
        $t->addColumn('days', 'integer');
        $t->addColumn('start_minute', 'integer');
        $t->addColumn('end_minute', 'integer');
        $t->addColumn('position', 'integer');
        $t->addColumn('screen_id', 'ulid');
        $t->addColumn('playlist_id', 'ulid');
        $t->setPrimaryKey(['id']);
        $t->addIndex(['screen_id'], 'IDX_A6908C9741A67722');
        $t->addIndex(['playlist_id'], 'IDX_A6908C976BBD148');
        $t->addForeignKeyConstraint('screen', ['screen_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_A6908C9741A67722');
        $t->addForeignKeyConstraint('playlist', ['playlist_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_A6908C976BBD148');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('schedule_rule');
    }
}
