<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Vivatura\VivaturaTranslator\Entity\LanguagePromptEntity;

class TranslationService
{
    private AnthropicClient $anthropicClient;
    private ContentExtractor $contentExtractor;
    private EntityRepository $productRepository;
    private EntityRepository $cmsPageRepository;
    private EntityRepository $snippetRepository;
    private EntityRepository $languageRepository;
    private EntityRepository $languagePromptRepository;
    private SystemConfigService $systemConfigService;

    public function __construct(
        AnthropicClient $anthropicClient,
        ContentExtractor $contentExtractor,
        EntityRepository $productRepository,
        EntityRepository $cmsPageRepository,
        EntityRepository $snippetRepository,
        EntityRepository $languageRepository,
        EntityRepository $languagePromptRepository,
        SystemConfigService $systemConfigService
    ) {
        $this->anthropicClient = $anthropicClient;
        $this->contentExtractor = $contentExtractor;
        $this->productRepository = $productRepository;
        $this->cmsPageRepository = $cmsPageRepository;
        $this->snippetRepository = $snippetRepository;
        $this->languageRepository = $languageRepository;
        $this->languagePromptRepository = $languagePromptRepository;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Translate a product to specified target languages
     *
     * @param string $productId
     * @param array $targetLanguageIds
     * @param Context $context
     * @return array Translation results per language
     */
    public function translateProduct(string $productId, array $targetLanguageIds, Context $context): array
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('translations');
        $product = $this->productRepository->search($criteria, $context)->first();

        if (!$product) {
            throw new \RuntimeException('Product not found: ' . $productId);
        }

        $content = $this->contentExtractor->extractProductContent($product);
        if (empty($content)) {
            return ['success' => true, 'message' => 'No translatable content found'];
        }

        $results = [];
        foreach ($targetLanguageIds as $languageId) {
            $language = $this->getLanguage($languageId, $context);
            $languageCode = $language->getLocale()?->getCode() ?? 'en-GB';
            $systemPrompt = $this->getSystemPromptForLanguage($languageId, $context);

            try {
                $translated = $this->anthropicClient->translateBatch($content, $languageCode, $systemPrompt);
                $this->saveProductTranslation($productId, $languageId, $translated, $context);
                $results[$languageCode] = ['success' => true, 'fields' => count($translated)];
            } catch (\Exception $e) {
                $results[$languageCode] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Translate a CMS page to specified target languages
     */
    public function translateCmsPage(string $pageId, array $targetLanguageIds, Context $context): array
    {
        $criteria = new Criteria([$pageId]);
        $criteria->addAssociation('sections.blocks.slots');
        $criteria->addAssociation('translations');
        $page = $this->cmsPageRepository->search($criteria, $context)->first();

        if (!$page) {
            throw new \RuntimeException('CMS page not found: ' . $pageId);
        }

        $content = $this->contentExtractor->extractCmsPageContent($page);
        if (empty($content)) {
            return ['success' => true, 'message' => 'No translatable content found'];
        }

        $results = [];
        foreach ($targetLanguageIds as $languageId) {
            $language = $this->getLanguage($languageId, $context);
            $languageCode = $language->getLocale()?->getCode() ?? 'en-GB';
            $systemPrompt = $this->getSystemPromptForLanguage($languageId, $context);

            try {
                $translated = $this->anthropicClient->translateBatch($content, $languageCode, $systemPrompt);
                $this->saveCmsPageTranslation($pageId, $page, $languageId, $translated, $context);
                $results[$languageCode] = ['success' => true, 'fields' => count($translated)];
            } catch (\Exception $e) {
                $results[$languageCode] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Translate a snippet to a target language
     */
    public function translateSnippet(string $snippetId, string $targetLanguageId, Context $context): array
    {
        $criteria = new Criteria([$snippetId]);
        $snippet = $this->snippetRepository->search($criteria, $context)->first();

        if (!$snippet) {
            throw new \RuntimeException('Snippet not found: ' . $snippetId);
        }

        $content = $this->contentExtractor->extractSnippetContent($snippet);
        if (empty($content)) {
            return ['success' => true, 'message' => 'No translatable content found'];
        }

        $language = $this->getLanguage($targetLanguageId, $context);
        $languageCode = $language->getLocale()?->getCode() ?? 'en-GB';
        $systemPrompt = $this->getSystemPromptForLanguage($targetLanguageId, $context);

        try {
            $translated = $this->anthropicClient->translateBatch($content, $languageCode, $systemPrompt);
            
            // Create new snippet for target language
            $this->createTranslatedSnippet($snippet, $targetLanguageId, $translated['value'], $context);
            
            return ['success' => true, 'language' => $languageCode];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get available languages for translation (excluding source language)
     */
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

    /**
     * Get system prompt for a specific language (with fallback to global)
     */
    private function getSystemPromptForLanguage(string $languageId, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('languageId', $languageId));
        
        /** @var LanguagePromptEntity|null $prompt */
        $prompt = $this->languagePromptRepository->search($criteria, $context)->first();

        if ($prompt && !empty($prompt->getSystemPrompt())) {
            return $prompt->getSystemPrompt();
        }

        // Fallback to global prompt
        return $this->systemConfigService->get('VivaturaTranslator.config.globalSystemPrompt') 
            ?? 'Du bist ein professioneller Übersetzer für E-Commerce Inhalte. Übersetze präzise und behalte den Ton und Stil des Originals bei.';
    }

    private function getLanguage(string $languageId, Context $context)
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        return $this->languageRepository->search($criteria, $context)->first();
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

    private function saveCmsPageTranslation(string $pageId, $page, string $languageId, array $translated, Context $context): void
    {
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

        // Note: Slot translations require more complex logic due to CMS structure
        // This is a simplified version - full implementation would update slot configs
    }

    private function createTranslatedSnippet($sourceSnippet, string $targetLanguageId, string $translatedValue, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('translationKey', $sourceSnippet->getTranslationKey()));
        $criteria->addFilter(new EqualsFilter('setId', $this->getSnippetSetForLanguage($targetLanguageId, $context)));

        $existing = $this->snippetRepository->search($criteria, $context)->first();

        if ($existing) {
            // Update existing
            $this->snippetRepository->update([
                [
                    'id' => $existing->getId(),
                    'value' => $translatedValue
                ]
            ], $context);
        } else {
            // Create new
            $this->snippetRepository->create([
                [
                    'id' => Uuid::randomHex(),
                    'translationKey' => $sourceSnippet->getTranslationKey(),
                    'value' => $translatedValue,
                    'setId' => $this->getSnippetSetForLanguage($targetLanguageId, $context),
                    'author' => 'VivaturaTranslator',
                ]
            ], $context);
        }
    }

    private function getSnippetSetForLanguage(string $languageId, Context $context): ?string
    {
        // This would need to look up the snippet set for the language
        // Simplified for now - in production you'd query snippet_set table
        return null;
    }
}
