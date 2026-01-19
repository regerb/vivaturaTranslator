<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Controller\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vivatura\VivTranslator\Service\TranslationService;

#[Route(defaults: ['_routeScope' => ['api']])]
class TranslationController extends AbstractController
{
    private TranslationService $translationService;
    private EntityRepository $languageRepository;

    public function __construct(
        TranslationService $translationService,
        EntityRepository $languageRepository
    ) {
        $this->translationService = $translationService;
        $this->languageRepository = $languageRepository;
    }

    /**
     * Get available target languages
     */
    #[Route(
        path: '/api/viv-translator/languages',
        name: 'api.viv_translator.languages',
        methods: ['GET']
    )]
    public function getLanguages(Context $context): JsonResponse
    {
        $languages = $this->translationService->getAvailableLanguages($context);
        return new JsonResponse(['languages' => $languages]);
    }

    /**
     * Translate a single product
     */
    #[Route(
        path: '/api/viv-translator/translate-product/{productId}',
        name: 'api.viv_translator.translate_product',
        methods: ['POST']
    )]
    public function translateProduct(string $productId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $targetLanguageIds = $data['targetLanguageIds'] ?? [];

        if (empty($targetLanguageIds)) {
            return new JsonResponse(['error' => 'No target languages specified'], 400);
        }

        try {
            $result = $this->translationService->translateProduct($productId, $targetLanguageIds, $context);
            return new JsonResponse(['success' => true, 'results' => $result]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Translate multiple products (bulk)
     */
    #[Route(
        path: '/api/viv-translator/translate-products',
        name: 'api.viv_translator.translate_products',
        methods: ['POST']
    )]
    public function translateProducts(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $productIds = $data['productIds'] ?? [];
        $targetLanguageIds = $data['targetLanguageIds'] ?? [];

        if (empty($productIds)) {
            return new JsonResponse(['error' => 'No products specified'], 400);
        }

        if (empty($targetLanguageIds)) {
            return new JsonResponse(['error' => 'No target languages specified'], 400);
        }

        $results = [];
        foreach ($productIds as $productId) {
            try {
                $results[$productId] = $this->translationService->translateProduct($productId, $targetLanguageIds, $context);
            } catch (\Exception $e) {
                $results[$productId] = ['error' => $e->getMessage()];
            }
        }

        return new JsonResponse(['success' => true, 'results' => $results]);
    }

    /**
     * Translate a single CMS page
     */
    #[Route(
        path: '/api/viv-translator/translate-cms-page/{pageId}',
        name: 'api.viv_translator.translate_cms_page',
        methods: ['POST']
    )]
    public function translateCmsPage(string $pageId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $targetLanguageIds = $data['targetLanguageIds'] ?? [];

        if (empty($targetLanguageIds)) {
            return new JsonResponse(['error' => 'No target languages specified'], 400);
        }

        try {
            $result = $this->translationService->translateCmsPage($pageId, $targetLanguageIds, $context);
            return new JsonResponse(['success' => true, 'results' => $result]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Translate a single snippet
     */
    #[Route(
        path: '/api/viv-translator/translate-snippet/{snippetId}',
        name: 'api.viv_translator.translate_snippet',
        methods: ['POST']
    )]
    public function translateSnippet(string $snippetId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $targetLanguageId = $data['targetLanguageId'] ?? null;

        if (empty($targetLanguageId)) {
            return new JsonResponse(['error' => 'No target language specified'], 400);
        }

        try {
            $result = $this->translationService->translateSnippet($snippetId, $targetLanguageId, $context);
            return new JsonResponse(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Translate multiple snippets (bulk)
     */
    #[Route(
        path: '/api/viv-translator/translate-snippets',
        name: 'api.viv_translator.translate_snippets',
        methods: ['POST']
    )]
    public function translateSnippets(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $snippetIds = $data['snippetIds'] ?? [];
        $targetLanguageId = $data['targetLanguageId'] ?? null;

        if (empty($snippetIds)) {
            return new JsonResponse(['error' => 'No snippets specified'], 400);
        }

        if (empty($targetLanguageId)) {
            return new JsonResponse(['error' => 'No target language specified'], 400);
        }

        $results = [];
        foreach ($snippetIds as $snippetId) {
            try {
                $results[$snippetId] = $this->translationService->translateSnippet($snippetId, $targetLanguageId, $context);
            } catch (\Exception $e) {
                $results[$snippetId] = ['error' => $e->getMessage()];
            }
        }

        return new JsonResponse(['success' => true, 'results' => $results]);
    }
}
