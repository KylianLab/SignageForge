<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDB1010Platform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migrations are written with the Schema API so they run on every
 * supported database. This checks, without needing those servers, that they
 * produce exactly the schema the entities describe on each platform.
 */
final class MigrationPortabilityTest extends KernelTestCase
{
    /** @return list<class-string<AbstractMigration>> every migration, oldest first */
    private static function migrations(): array
    {
        $files = glob(\dirname(__DIR__, 2).'/migrations/Version*.php') ?: [];
        sort($files);

        return array_map(static function (string $file): string {
            $class = 'DoctrineMigrations\\'.basename($file, '.php');
            \assert(is_subclass_of($class, AbstractMigration::class));

            return $class;
        }, $files);
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function platforms(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform()];
        yield 'MySQL 8' => [new MySQL80Platform()];
        yield 'MariaDB 10.10+' => [new MariaDB1010Platform()];
        yield 'PostgreSQL' => [new PostgreSQL120Platform()];
    }

    // SchemaTool warns that ORM 4 changes how it builds schemas; the comparison
    // here is about today's ORM. Revisit when upgrading to ORM 4.
    #[DataProvider('platforms')]
    #[IgnoreDeprecations]
    public function testMigrationsMatchTheEntities(AbstractPlatform $platform): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $fromEntities = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());

        // Migrations get a schema configured like the connection (table options).
        $fromMigrations = new Schema(schemaConfig: $em->getConnection()->createSchemaManager()->createSchemaConfig());
        foreach (self::migrations() as $class) {
            (new $class($em->getConnection(), new NullLogger()))->up($fromMigrations);
        }

        // Compared on substance (columns, types, indexes, keys), not on
        // column order, which differs after an ALTER TABLE.
        $diff = (new Comparator($platform))->compareSchemas($fromMigrations, $fromEntities);
        self::assertSame([], $platform->getAlterSchemaSQL($diff), 'The migrations do not produce the schema of the entities.');

        // Every statement must also be valid DDL for this platform.
        self::assertNotEmpty($fromMigrations->toSql($platform));
    }
}
