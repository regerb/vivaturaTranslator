<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class TranslateSnippetSetMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly string $jobId,
        private readonly string $sourceSetId,
        private readonly string $targetSetId,
        private readonly ?array $snippetIds = null,
        private readonly bool $overwriteExisting = false
    ) {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getSourceSetId(): string
    {
        return $this->sourceSetId;
    }

    public function getTargetSetId(): string
    {
        return $this->targetSetId;
    }

    public function getSnippetIds(): ?array
    {
        return $this->snippetIds;
    }

    public function getOverwriteExisting(): bool
    {
        return $this->overwriteExisting;
    }
}
