<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1737640800CreateTranslationJobTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1737640800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `viv_translator_job` (
                `id` BINARY(16) NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `entity_id` VARCHAR(255) NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT "pending",
                `target_language_ids` JSON NULL,
                `result` JSON NULL,
                `started_at` DATETIME(3) NULL,
                `finished_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                INDEX `idx.status` (`status`),
                INDEX `idx.type_entity` (`type`, `entity_id`),
                INDEX `idx.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
