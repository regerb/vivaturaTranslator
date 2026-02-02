<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class TranslateCmsPageMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly string $jobId,
        private readonly string $pageId,
        private readonly array $targetLanguageIds,
        private readonly bool $overwriteExisting = false
    ) {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getPageId(): string
    {
        return $this->pageId;
    }

    public function getTargetLanguageIds(): array
    {
        return $this->targetLanguageIds;
    }

    public function getOverwriteExisting(): bool
    {
        return $this->overwriteExisting;
    }
}
