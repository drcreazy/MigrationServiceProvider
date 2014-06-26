<?php

namespace Knp\Migration;

use Silex\Application;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Connection;
use Symfony\Component\Finder\Finder;

class Manager
{
    const DEFAULT_NAMESPACE = '\\Migration\\';

    private $application;

    private $schema;

    private $connection;

    private $currentVersion = null;

    private $migrationInfos = array();

    private $migrationExecuted = 0;

    private $namespace;

    public function __construct(
        Connection $connection,
        Application $application,
        Finder $finder,
        $namespace
    ) {
        $this->schema      = $connection->getSchemaManager()->createSchema();
        $this->toSchema    = clone($this->schema);
        $this->connection  = $connection;
        $this->finder      = $finder;
        $this->application = $application;
        $this->namespace    = $namespace;
    }

    private function buildSchema(Schema $schema)
    {
        $queries = $this->schema->getMigrateToSql($schema, $this->connection->getDatabasePlatform());

        foreach ($queries as $query) {
            $this->connection->exec($query);
        }
    }

    private function findMigrations($from)
    {
        $finder     = clone($this->finder);
        $migrations = array();

        $finder
            ->files()
            ->name('Migration*.php')
            ->sortByName()
        ;

        foreach ($finder as $migration) {
            if (preg_match('/^(Migration(\d+)).php$/', basename($migration), $matches)) {

                list(, $class, $version) = $matches;

                if ((int) ltrim($version, 0) > $from) {
                    require_once $migration;

                    $fqcn = $this->namespace . $class;

                    if (!class_exists($fqcn)) {
                        throw new \RuntimeException(sprintf('Could not find class "%s" in "%s"', $fqcn, $migration));
                    }

                    $migrations[] = new $fqcn();
                }
            }
        }

        return $migrations;
    }

    public function getMigrationInfos()
    {
        return $this->migrationInfos;
    }

    public function getMigrationExecuted()
    {
        return $this->migrationExecuted;
    }

    public function getCurrentVersion()
    {
        if (is_null($this->currentVersion)) {
            $this->currentVersion = $this->conn->fetchColumn('SELECT schema_version FROM schema_version');
        }

        return $this->currentVersion;
    }

    public function setCurrentVersion($version)
    {
        $this->currentVersion = $version;
        $this->connection->executeUpdate('UPDATE schema_version SET schema_version = ?', array($version));
    }

    public function hasVersionInfo()
    {
        return $this->schema->hasTable('schema_version');
    }

    public function createVersionInfo()
    {
        $schema = clone($this->schema);

        $schemaVersion = $schema->createTable('schema_version');
        $schemaVersion->addColumn('schema_version', 'integer', array('unsigned' => true, 'default' => 0));

        $this->buildSchema($schema);

        $this->connection->insert('schema_version', array('schema_version' => 0));
    }

    public function migrate()
    {
        $from    = $this->connection->fetchColumn('SELECT schema_version FROM schema_version');
        $queries = array();

        $migrations = $this->findMigrations($from);

        if (count($migrations) == 0) {
            return null;
        }

        foreach ($migrations as $migration) {
            $migration->schemaUp($this->toSchema);
        }

        $this->buildSchema($this->toSchema);

        foreach ($migrations as $migration) {
            $migration->appUp($this->application);
        }

        $migrationInfos = array();

        foreach ($migrations as $migration) {
            if (null !== $migration->getMigrationInfo()) {
                $migrationInfos[$migration->getVersion()] = $migration->getMigrationInfo();
            }

            $this->migrationExecuted++;
        }

        $this->migrationInfos = $migrationInfos;

        $this->setCurrentVersion($migration->getVersion());

        return true;
    }
}
