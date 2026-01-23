<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\MessageHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Vivatura\VivaturaTranslator\Message\TranslateCmsPageMessage;
use Vivatura\VivaturaTranslator\Message\TranslateProductMessage;
use Vivatura\VivaturaTranslator\Message\TranslateSnippetSetMessage;
use Vivatura\VivaturaTranslator\Service\TranslationService;

#[AsMessageHandler]
class TranslationMessageHandler
{
    public function __construct(
        private readonly TranslationService $translationService,
        private readonly EntityRepository $translationJobRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(TranslateProductMessage|TranslateCmsPageMessage|TranslateSnippetSetMessage $message): void
    {
        $context = Context::createDefaultContext();
        $jobId = $message->getJobId();

        try {
            $this->updateJobStatus($jobId, 'processing', null, $context);

            if ($message instanceof TranslateProductMessage) {
                $result = $this->handleProductTranslation($message, $context);
            } elseif ($message instanceof TranslateCmsPageMessage) {
                $result = $this->handleCmsPageTranslation($message, $context);
            } else {
                $result = $this->handleSnippetSetTranslation($message, $context);
            }

            $this->updateJobStatus($jobId, 'completed', $result, $context);

        } catch (\Exception $e) {
            $this->logger->error('Translation job failed', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateJobStatus($jobId, 'failed', ['error' => $e->getMessage()], $context);
        }
    }

    private function handleProductTranslation(TranslateProductMessage $message, Context $context): array
    {
        return $this->translationService->translateProduct(
            $message->getProductId(),
            $message->getTargetLanguageIds(),
            $context
        );
    }

    private function handleCmsPageTranslation(TranslateCmsPageMessage $message, Context $context): array
    {
        return $this->translationService->translateCmsPage(
            $message->getPageId(),
            $message->getTargetLanguageIds(),
            $context
        );
    }

    private function handleSnippetSetTranslation(TranslateSnippetSetMessage $message, Context $context): array
    {
        return $this->translationService->translateSnippetSet(
            $message->getSourceSetId(),
            $message->getTargetSetId(),
            $message->getSnippetIds(),
            $context
        );
    }

    private function updateJobStatus(string $jobId, string $status, ?array $result, Context $context): void
    {
        $data = [
            'id' => $jobId,
            'status' => $status,
        ];

        if ($result !== null) {
            $data['result'] = $result;
        }

        if ($status === 'processing') {
            $data['startedAt'] = new \DateTimeImmutable();
        }

        if ($status === 'completed' || $status === 'failed') {
            $data['finishedAt'] = new \DateTimeImmutable();
        }

        $this->translationJobRepository->update([$data], $context);
    }
}
