<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial Signage Forge schema.
 *
 * Written with the Schema API instead of raw SQL so the same migration runs on
 * SQLite, MariaDB/MySQL and PostgreSQL. Keep future migrations portable too.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, organizations, screens, pairing, media, playlists';
    }

    public function up(Schema $schema): void
    {
        $t = $schema->createTable('organization');
        $t->addColumn('id', 'ulid');
        $t->addColumn('name', 'string', ['length' => 120]);
        $t->addColumn('slug', 'string', ['length' => 80]);
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('updated_at', 'datetime_immutable');
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['slug'], 'UNIQ_C1EE637C989D9B62');

        $t = $schema->createTable('app_user');
        $t->addColumn('id', 'ulid');
        $t->addColumn('email', 'string', ['length' => 180]);
        $t->addColumn('name', 'string', ['notnull' => false, 'length' => 120]);
        $t->addColumn('password_hash', 'string', ['notnull' => false, 'length' => 255]);
        $t->addColumn('auth_source', 'string', ['length' => 16]);
        $t->addColumn('external_id', 'string', ['notnull' => false, 'length' => 255]);
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('updated_at', 'datetime_immutable');
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['email'], 'UNIQ_88BDF3E9E7927C74');

        $t = $schema->createTable('membership');
        $t->addColumn('id', 'ulid');
        $t->addColumn('role', 'string', ['length' => 16]);
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('user_id', 'ulid');
        $t->addColumn('organization_id', 'ulid');
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['user_id', 'organization_id'], 'uniq_membership_user_org');
        $t->addIndex(['user_id'], 'IDX_86FFD285A76ED395');
        $t->addIndex(['organization_id'], 'IDX_86FFD28532C8A3DE');
        $t->addForeignKeyConstraint('app_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_86FFD285A76ED395');
        $t->addForeignKeyConstraint('organization', ['organization_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_86FFD28532C8A3DE');

        $t = $schema->createTable('playlist');
        $t->addColumn('id', 'ulid');
        $t->addColumn('name', 'string', ['length' => 80]);
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('updated_at', 'datetime_immutable');
        $t->addColumn('organization_id', 'ulid');
        $t->setPrimaryKey(['id']);
        $t->addIndex(['organization_id'], 'IDX_D782112D32C8A3DE');
        $t->addForeignKeyConstraint('organization', ['organization_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_D782112D32C8A3DE');

        $t = $schema->createTable('media');
        $t->addColumn('id', 'ulid');
        $t->addColumn('name', 'string', ['length' => 120]);
        $t->addColumn('type', 'string', ['length' => 16]);
        $t->addColumn('storage_key', 'string', ['length' => 2048]);
        $t->addColumn('mime_type', 'string', ['notnull' => false, 'length' => 120]);
        $t->addColumn('size_bytes', 'integer', ['notnull' => false]);
        $t->addColumn('duration_ms', 'integer');
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('updated_at', 'datetime_immutable');
        $t->addColumn('organization_id', 'ulid');
        $t->setPrimaryKey(['id']);
        $t->addIndex(['organization_id'], 'IDX_6A2CA10C32C8A3DE');
        $t->addForeignKeyConstraint('organization', ['organization_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_6A2CA10C32C8A3DE');

        $t = $schema->createTable('playlist_item');
        $t->addColumn('id', 'ulid');
        $t->addColumn('position', 'integer');
        $t->addColumn('duration_ms', 'integer');
        $t->addColumn('playlist_id', 'ulid');
        $t->addColumn('media_id', 'ulid');
        $t->setPrimaryKey(['id']);
        $t->addIndex(['playlist_id'], 'IDX_BF02127C6BBD148');
        $t->addIndex(['media_id'], 'IDX_BF02127CEA9FDD75');
        $t->addForeignKeyConstraint('playlist', ['playlist_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_BF02127C6BBD148');
        $t->addForeignKeyConstraint('media', ['media_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_BF02127CEA9FDD75');

        $t = $schema->createTable('screen');
        $t->addColumn('id', 'ulid');
        $t->addColumn('name', 'string', ['length' => 80]);
        $t->addColumn('location', 'string', ['notnull' => false, 'length' => 120]);
        $t->addColumn('device_token', 'string', ['length' => 64]);
        $t->addColumn('orientation', 'string', ['length' => 16]);
        $t->addColumn('last_seen_at', 'datetime_immutable', ['notnull' => false]);
        $t->addColumn('user_agent', 'string', ['notnull' => false, 'length' => 255]);
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('updated_at', 'datetime_immutable');
        $t->addColumn('organization_id', 'ulid');
        $t->addColumn('playlist_id', 'ulid', ['notnull' => false]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['device_token'], 'UNIQ_DF4C613099B2415C');
        $t->addIndex(['organization_id'], 'IDX_DF4C613032C8A3DE');
        $t->addIndex(['playlist_id'], 'IDX_DF4C61306BBD148');
        $t->addForeignKeyConstraint('organization', ['organization_id'], ['id'], ['onDelete' => 'CASCADE', 'deferrable' => false], 'FK_DF4C613032C8A3DE');
        $t->addForeignKeyConstraint('playlist', ['playlist_id'], ['id'], ['onDelete' => 'SET NULL', 'deferrable' => false], 'FK_DF4C61306BBD148');

        $t = $schema->createTable('pairing_session');
        $t->addColumn('id', 'ulid');
        $t->addColumn('code', 'string', ['length' => 12]);
        $t->addColumn('device_token', 'string', ['length' => 64]);
        $t->addColumn('claimed', 'boolean');
        $t->addColumn('user_agent', 'string', ['notnull' => false, 'length' => 255]);
        $t->addColumn('expires_at', 'datetime_immutable');
        $t->addColumn('created_at', 'datetime_immutable');
        $t->addColumn('screen_id', 'ulid', ['notnull' => false]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['code'], 'UNIQ_9CD1E31A77153098');
        $t->addUniqueIndex(['device_token'], 'UNIQ_9CD1E31A99B2415C');
        $t->addIndex(['screen_id'], 'IDX_9CD1E31A41A67722');
        $t->addForeignKeyConstraint('screen', ['screen_id'], ['id'], ['onDelete' => 'SET NULL', 'deferrable' => false], 'FK_9CD1E31A41A67722');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('pairing_session');
        $schema->dropTable('screen');
        $schema->dropTable('playlist_item');
        $schema->dropTable('media');
        $schema->dropTable('playlist');
        $schema->dropTable('membership');
        $schema->dropTable('app_user');
        $schema->dropTable('organization');
    }
}
