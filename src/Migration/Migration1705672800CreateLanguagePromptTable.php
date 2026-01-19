<?php declare(strict_types=1);

namespace Vivatura\VivTranslator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1705672800CreateLanguagePromptTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1705672800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `viv_translator_language_prompt` (
                `id` BINARY(16) NOT NULL,
                `language_id` BINARY(16) NOT NULL,
                `system_prompt` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.language_id` (`language_id`),
                CONSTRAINT `fk.viv_translator_language_prompt.language_id`
                    FOREIGN KEY (`language_id`)
                    REFERENCES `language` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
