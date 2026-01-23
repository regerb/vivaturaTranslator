<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class TranslateProductMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly string $jobId,
        private readonly string $productId,
        private readonly array $targetLanguageIds
    ) {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getTargetLanguageIds(): array
    {
        return $this->targetLanguageIds;
    }
}
