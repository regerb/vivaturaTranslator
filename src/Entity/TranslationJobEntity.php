<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class TranslationJobEntity extends Entity
{
    use EntityIdTrait;

    protected string $type;
    protected string $entityId;
    protected string $status;
    protected ?array $targetLanguageIds = null;
    protected ?array $result = null;
    protected ?\DateTimeInterface $startedAt = null;
    protected ?\DateTimeInterface $finishedAt = null;

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function setEntityId(string $entityId): void
    {
        $this->entityId = $entityId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getTargetLanguageIds(): ?array
    {
        return $this->targetLanguageIds;
    }

    public function setTargetLanguageIds(?array $targetLanguageIds): void
    {
        $this->targetLanguageIds = $targetLanguageIds;
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function setResult(?array $result): void
    {
        $this->result = $result;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeInterface $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }
}
