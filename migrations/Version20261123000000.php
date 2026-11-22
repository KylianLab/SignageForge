<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Screen groups: a group has a default playlist and a schedule that its
 * screens follow. Schema API only, so it runs on SQLite, MariaDB/MySQL and
 * PostgreSQL.
 */
final class Version20261123000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Screen groups, and schedules that belong to a screen or a group';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable('screen_group');
        $t->addColumn('id', 'ulid');
        $t->addColumn('name', 'string', ['length' => 80]);
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('updated_at', 'datetime_immutable');
        $t->addColumn('organization_id', 'ulid');
        $t->addColumn('playlist_id', 'ulid', ['notnull' => false]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['organization_id'], 'IDX_10C7648132C8A3DE');
        $t->addIndex(['playlist_id'], 'IDX_10C764816BBD148');
        $t->addForeignKeyConstraint('organization', ['organization_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_10C7648132C8A3DE');
        $t->addForeignKeyConstraint('playlist', ['playlist_id'], ['id'], ['onDelete' => 'SET NULL', 'deferrable' => false], 'FK_10C764816BBD148');

        $screen = $schema->getTable('screen');
        $screen->addColumn('group_id', 'ulid', ['notnull' => false]);
        $screen->addIndex(['group_id'], 'IDX_DF4C6130FE54D947');
        $screen->addForeignKeyConstraint('screen_group', ['group_id'], ['id'], ['onDelete' => 'SET NULL', 'deferrable' => false], 'FK_DF4C6130FE54D947');

        $rule = $schema->getTable('schedule_rule');
        $rule->modifyColumn('screen_id', ['notnull' => false]);
        $rule->addColumn('group_id', 'ulid', ['notnull' => false]);
        $rule->addIndex(['group_id'], 'IDX_A6908C97FE54D947');
        $rule->addForeignKeyConstraint('screen_group', ['group_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_A6908C97FE54D947');
    }

    public function down(Schema $schema): void
    {
        $rule = $schema->getTable('schedule_rule');
        $rule->removeForeignKey('FK_A6908C97FE54D947');
        $rule->dropIndex('IDX_A6908C97FE54D947');
        $rule->dropColumn('group_id');
        $rule->modifyColumn('screen_id', ['notnull' => true]);

        $screen = $schema->getTable('screen');
        $screen->removeForeignKey('FK_DF4C6130FE54D947');
        $screen->dropIndex('IDX_DF4C6130FE54D947');
        $screen->dropColumn('group_id');

        $schema->dropTable('screen_group');
    }
}
