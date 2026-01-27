<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Vivatura\VivaturaTranslator\Entity\LanguagePromptEntity;

class TranslationService
{
    public function __construct(
        private readonly AnthropicClient $anthropicClient,
        private readonly ContentExtractor $contentExtractor,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $snippetRepository,
        private readonly EntityRepository $snippetSetRepository,
        private readonly EntityRepository $languageRepository,
        private readonly ?EntityRepository $languagePromptRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $cmsSlotRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    // ========================================
    // PRODUCT TRANSLATION
    // ========================================

    public function translateProduct(string $productId, array $targetLanguageIds, Context $context): array
    {
        $this->logger->info('TranslationService: Starting product translation', [
            'productId' => $productId,
            'targetLanguageIds' => $targetLanguageIds,
        ]);

        // Create context with source language to get the source texts
        $sourceLanguageId = $this->getSourceLanguageId($context);
        $this->logger->info('TranslationService: Source language resolved', [
            'sourceLanguageId' => $sourceLanguageId,
            'configuredSourceLanguage' => $this->systemConfigService->get('VivaturaTranslator.config.sourceLanguage') ?? 'de-DE',
        ]);

        $sourceContext = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [$sourceLanguageId]
        );

        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('translations');
        $product = $this->productRepository->search($criteria, $sourceContext)->first();

        if (!$product) {
            $this->logger->error('TranslationService: Product not found', ['productId' => $productId]);
            throw new \RuntimeException('Product not found: ' . $productId);
        }

        $this->logger->info('TranslationService: Product loaded', [
            'productId' => $productId,
            'productName' => $product->getName(),
            'productNumber' => $product->getProductNumber(),
        ]);

        $content = $this->contentExtractor->extractProductContent($product);

        $this->logger->info('TranslationService: Content extracted', [
            'productId' => $productId,
            'contentFields' => array_keys($content),
            'contentCount' => count($content),
            'content' => $content,
        ]);

        if (empty($content)) {
            $this->logger->warning('TranslationService: No translatable content found', [
                'productId' => $productId,
            ]);
            return ['success' => true, 'message' => 'No translatable content found'];
        }

        $results = [];
        foreach ($targetLanguageIds as $languageId) {
            $language = $this->getLanguage($languageId, $context);
            $languageCode = $language->getLocale()?->getCode() ?? 'en-GB';
            $systemPrompt = $this->getSystemPromptForLanguage($languageId, $context);

            $this->logger->info('TranslationService: Translating to language', [
                'productId' => $productId,
                'targetLanguageId' => $languageId,
                'targetLanguageCode' => $languageCode,
            ]);

            try {
                $translated = $this->anthropicClient->translateBatch($content, $languageCode, $systemPrompt);

                $this->logger->info('TranslationService: Translation received from API', [
                    'productId' => $productId,
                    'targetLanguageCode' => $languageCode,
                    'translatedFields' => array_keys($translated),
                    'translated' => $translated,
                ]);

                $this->saveProductTranslation($productId, $languageId, $translated, $context);

                $this->logger->info('TranslationService: Translation saved', [
                    'productId' => $productId,
                    'targetLanguageId' => $languageId,
                ]);

                $results[$languageCode] = ['success' => true, 'fields' => count($translated)];
            } catch (\Exception $e) {
                $this->logger->error('TranslationService: Translation failed', [
                    'productId' => $productId,
                    'targetLanguageCode' => $languageCode,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $results[$languageCode] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // ========================================
    // CMS PAGE TRANSLATION (WITH SLOTS)
    // ========================================

    public function translateCmsPage(string $pageId, array $targetLanguageIds, Context $context): array
    {
        // Create context with source language to get the source texts
        $sourceLanguageId = $this->getSourceLanguageId($context);
        $sourceContext = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [$sourceLanguageId]
        );

        $criteria = new Criteria([$pageId]);
        $criteria->addAssociation('sections.blocks.slots');
        $criteria->addAssociation('translations');
        $page = $this->cmsPageRepository->search($criteria, $sourceContext)->first();

        if (!$page) {
            throw new \RuntimeException('CMS page not found: ' . $pageId);
        }

        $content = $this->contentExtractor->extractCmsPageContent($page);
        if (empty($content)) {
            return ['success' => true, 'message' => 'No translatable content found'];
        }

        // Collect slot information for later update
        $slotMapping = $this->buildSlotMapping($page);

        $results = [];
        foreach ($targetLanguageIds as $languageId) {
            $language = $this->getLanguage($languageId, $context);
            $languageCode = $language->getLocale()?->getCode() ?? 'en-GB';
            $systemPrompt = $this->getSystemPromptForLanguage($languageId, $context);

            try {
                $translated = $this->anthropicClient->translateBatch($content, $languageCode, $systemPrompt);
                $this->saveCmsPageTranslation($pageId, $page, $languageId, $translated, $slotMapping, $context);
                $results[$languageCode] = ['success' => true, 'fields' => count($translated)];
            } catch (\Exception $e) {
                $results[$languageCode] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function buildSlotMapping($page): array
    {
        $mapping = [];
        $slotIndex = 0;

        $sections = $page->getSections();
        if ($sections === null) {
            return $mapping;
        }

        foreach ($sections as $section) {
            $blocks = $section->getBlocks();
            if ($blocks === null) continue;

            foreach ($blocks as $block) {
                $slots = $block->getSlots();
                if ($slots === null) continue;

                foreach ($slots as $slot) {
                    $mapping[$slotIndex] = [
                        'slotId' => $slot->getId(),
                        'config' => $slot->getConfig() ?? [],
                    ];
                    $slotIndex++;
                }
            }
        }

        return $mapping;
    }

    private function saveCmsPageTranslation(string $pageId, $page, string $languageId, array $translated, array $slotMapping, Context $context): void
    {
        // Create language-specific context
        $translatedContext = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [$languageId]
        );

        // Update page name if translated
        if (isset($translated['name'])) {
            $this->cmsPageRepository->update([
                [
                    'id' => $pageId,
                    'translations' => [
                        $languageId => ['name' => $translated['name']]
                    ]
                ]
            ], $context);
        }

        // Update slot translations
        $slotUpdates = [];
        foreach ($translated as $key => $value) {
            if (str_starts_with($key, 'slot_')) {
                // Parse key: slot_0_content -> index=0, field=content
                preg_match('/slot_(\d+)_(.+)/', $key, $matches);
                if (count($matches) === 3) {
                    $slotIndex = (int) $matches[1];
                    $field = $matches[2];

                    if (isset($slotMapping[$slotIndex])) {
                        $slotId = $slotMapping[$slotIndex]['slotId'];
                        $config = $slotMapping[$slotIndex]['config'];

                        // Update the config value
                        if (isset($config[$field])) {
                            $config[$field]['value'] = $value;

                            if (!isset($slotUpdates[$slotId])) {
                                $slotUpdates[$slotId] = $config;
                            } else {
                                $slotUpdates[$slotId][$field] = $config[$field];
                            }
                        }
                    }
                }
            }
        }

        // Save slot updates
        foreach ($slotUpdates as $slotId => $newConfig) {
            $this->cmsSlotRepository->update([
                [
                    'id' => $slotId,
                    'translations' => [
                        $languageId => ['config' => $newConfig]
                    ]
                ]
            ], $context);
        }
    }

    // ========================================
    // SNIPPET TRANSLATION
    // ========================================

    public function translateSnippetSet(string $sourceSetId, string $targetSetId, ?array $snippetIds, Context $context): array
    {
        // Get target set info for language detection
        $targetSet = $this->snippetSetRepository->search(new Criteria([$targetSetId]), $context)->first();
        if (!$targetSet) {
            throw new \RuntimeException('Target snippet set not found: ' . $targetSetId);
        }

        $targetIso = $targetSet->getIso();
        $targetLanguageId = $this->getLanguageIdByIso($targetIso, $context);
        $systemPrompt = $targetLanguageId
            ? $this->getSystemPromptForLanguage($targetLanguageId, $context)
            : $this->getDefaultSystemPrompt();

        // Get source snippets
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('setId', $sourceSetId));

        if (!empty($snippetIds)) {
            $criteria->setIds($snippetIds);
        }

        $sourceSnippets = $this->snippetRepository->search($criteria, $context)->getEntities();

        if ($sourceSnippets->count() === 0) {
            return ['success' => true, 'message' => 'No snippets found in source set'];
        }

        // Batch translate all snippets in chunks
        $textsToTranslate = [];
        $snippetKeyMap = [];

        foreach ($sourceSnippets as $snippet) {
            $value = $snippet->getValue();
            if (!empty($value)) {
                $key = $snippet->getTranslationKey();
                $textsToTranslate[$key] = $value;
                $snippetKeyMap[$key] = $snippet;
            }
        }

        if (empty($textsToTranslate)) {
            return ['success' => true, 'message' => 'No translatable content found'];
        }

        $chunks = array_chunk($textsToTranslate, 50, true);
        $translatedTexts = [];
        $errors = [];

        foreach ($chunks as $chunk) {
            try {
                $chunkResult = $this->anthropicClient->translateBatch($chunk, $targetIso, $systemPrompt);
                $translatedTexts = array_merge($translatedTexts, $chunkResult);
            } catch (\Exception $e) {
                $this->logger->error('TranslationService: Chunk translation failed', [
                    'error' => $e->getMessage(),
                    'chunkSize' => count($chunk)
                ]);
                // Continue with next chunk, but log error
                // We could also add placeholder errors for these keys
                foreach (array_keys($chunk) as $key) {
                    $errors[$key] = $e->getMessage();
                }
            }
        }

        // Save translated snippets
        $successCount = 0;
        $errorCount = count($errors);
        $results = [];

        // Add errors from failed chunks
        foreach ($errors as $key => $message) {
            $results[$key] = ['success' => false, 'error' => $message];
        }

        foreach ($translatedTexts as $key => $translatedValue) {
            try {
                $this->saveOrUpdateSnippet($key, $translatedValue, $targetSetId, $context);
                $results[$key] = ['success' => true];
                $successCount++;
            } catch (\Exception $e) {
                $results[$key] = ['success' => false, 'error' => $e->getMessage()];
                $errorCount++;
            }
        }

        return [
            'success' => true,
            'translated' => $successCount,
            'errors' => $errorCount,
            'total' => count($textsToTranslate),
            'details' => $results,
        ];
    }

    public function translateSingleSnippet(string $snippetId, string $targetSetId, Context $context): array
    {
        $snippet = $this->snippetRepository->search(new Criteria([$snippetId]), $context)->first();

        if (!$snippet) {
            throw new \RuntimeException('Snippet not found: ' . $snippetId);
        }

        $value = $snippet->getValue();
        if (empty($value)) {
            return ['success' => true, 'message' => 'Snippet has no value'];
        }

        // Get target set info
        $targetSet = $this->snippetSetRepository->search(new Criteria([$targetSetId]), $context)->first();
        if (!$targetSet) {
            throw new \RuntimeException('Target snippet set not found: ' . $targetSetId);
        }

        $targetIso = $targetSet->getIso();
        $targetLanguageId = $this->getLanguageIdByIso($targetIso, $context);
        $systemPrompt = $targetLanguageId
            ? $this->getSystemPromptForLanguage($targetLanguageId, $context)
            : $this->getDefaultSystemPrompt();

        try {
            $translated = $this->anthropicClient->translate($value, $targetIso, $systemPrompt);
            $this->saveOrUpdateSnippet($snippet->getTranslationKey(), $translated, $targetSetId, $context);

            return [
                'success' => true,
                'translationKey' => $snippet->getTranslationKey(),
                'targetIso' => $targetIso,
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Translation failed: ' . $e->getMessage());
        }
    }

    private function saveOrUpdateSnippet(string $translationKey, string $value, string $setId, Context $context): void
    {
        // Check if snippet already exists in target set
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('translationKey', $translationKey));
        $criteria->addFilter(new EqualsFilter('setId', $setId));

        $existing = $this->snippetRepository->search($criteria, $context)->first();

        if ($existing) {
            // Update existing
            $this->snippetRepository->update([
                [
                    'id' => $existing->getId(),
                    'value' => $value,
                ]
            ], $context);
        } else {
            // Create new
            $this->snippetRepository->create([
                [
                    'id' => Uuid::randomHex(),
                    'translationKey' => $translationKey,
                    'value' => $value,
                    'setId' => $setId,
                    'author' => 'VivaturaTranslator',
                ]
            ], $context);
        }
    }

    private function getLanguageIdByIso(string $iso, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addAssociation('locale');

        $languages = $this->languageRepository->search($criteria, $context);

        foreach ($languages as $language) {
            if ($language->getLocale()?->getCode() === $iso) {
                return $language->getId();
            }
        }

        return null;
    }

    public function getAvailableModels(): array
    {
        return $this->anthropicClient->getAvailableModels();
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    public function getAvailableLanguages(Context $context): array
    {
        $sourceLanguageCode = $this->systemConfigService->get('VivaturaTranslator.config.sourceLanguage') ?? 'de-DE';

        $criteria = new Criteria();
        $criteria->addAssociation('locale');

        $languages = $this->languageRepository->search($criteria, $context);

        $result = [];
        foreach ($languages as $language) {
            $localeCode = $language->getLocale()?->getCode();
            if ($localeCode !== $sourceLanguageCode) {
                $result[] = [
                    'id' => $language->getId(),
                    'name' => $language->getName(),
                    'locale' => $localeCode,
                ];
            }
        }

        return $result;
    }

    private function getSystemPromptForLanguage(string $languageId, Context $context): string
    {
        if ($this->languagePromptRepository !== null) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('languageId', $languageId));

            /** @var LanguagePromptEntity|null $prompt */
            $prompt = $this->languagePromptRepository->search($criteria, $context)->first();

            if ($prompt && !empty($prompt->getSystemPrompt())) {
                return $prompt->getSystemPrompt();
            }
        }

        return $this->getDefaultSystemPrompt();
    }

    private function getDefaultSystemPrompt(): string
    {
        return $this->systemConfigService->get('VivaturaTranslator.config.globalSystemPrompt')
            ?? 'Du bist ein professioneller Übersetzer für E-Commerce Inhalte. Übersetze präzise und behalte den Ton und Stil des Originals bei. Gib nur die Übersetzung zurück, keine Erklärungen.';
    }

    private function getLanguage(string $languageId, Context $context)
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        return $this->languageRepository->search($criteria, $context)->first();
    }

    private function getSourceLanguageId(Context $context): string
    {
        $sourceLanguageCode = $this->systemConfigService->get('VivaturaTranslator.config.sourceLanguage') ?? 'de-DE';

        $criteria = new Criteria();
        $criteria->addAssociation('locale');

        $languages = $this->languageRepository->search($criteria, $context);

        foreach ($languages as $language) {
            if ($language->getLocale()?->getCode() === $sourceLanguageCode) {
                return $language->getId();
            }
        }

        // Fallback to system default language
        return $context->getLanguageId();
    }

    private function saveProductTranslation(string $productId, string $languageId, array $translated, Context $context): void
    {
        $translationData = [
            'id' => $productId,
            'translations' => [
                $languageId => $this->buildProductTranslationPayload($translated)
            ]
        ];

        $translatedContext = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [$languageId]
        );

        $this->productRepository->update([$translationData], $translatedContext);
    }

    private function buildProductTranslationPayload(array $translated): array
    {
        $payload = [];
        $customFields = [];

        foreach ($translated as $field => $value) {
            if (str_starts_with($field, 'customFields.')) {
                $customFieldKey = substr($field, strlen('customFields.'));
                $customFields[$customFieldKey] = $value;
            } else {
                $payload[$field] = $value;
            }
        }

        if (!empty($customFields)) {
            $payload['customFields'] = $customFields;
        }

        return $payload;
    }
}
