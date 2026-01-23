<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Controller\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Vivatura\VivaturaTranslator\Message\TranslateCmsPageMessage;
use Vivatura\VivaturaTranslator\Message\TranslateProductMessage;
use Vivatura\VivaturaTranslator\Message\TranslateSnippetSetMessage;
use Vivatura\VivaturaTranslator\Service\TranslationService;

#[Route(defaults: ['_routeScope' => ['api']])]
class TranslationController extends AbstractController
{
    public function __construct(
        private readonly TranslationService $translationService,
        private readonly EntityRepository $languageRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $snippetSetRepository,
        private readonly EntityRepository $snippetRepository,
        private readonly EntityRepository $translationJobRepository,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    // ========================================
    // LANGUAGE ENDPOINTS
    // ========================================

    #[Route(
        path: '/api/_action/vivatura-translator/languages',
        name: 'api.action.vivatura_translator.languages',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function getLanguages(Context $context): JsonResponse
    {
        $languages = $this->translationService->getAvailableLanguages($context);
        return new JsonResponse(['languages' => $languages]);
    }

    // ========================================
    // PRODUCT ENDPOINTS
    // ========================================

    #[Route(
        path: '/api/_action/vivatura-translator/products',
        name: 'api.action.vivatura_translator.products',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function getProducts(Request $request, Context $context): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));
        $search = $request->query->get('search', '');

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset(($page - 1) * $limit);
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->addAssociation('translations');

        if (!empty($search)) {
            $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter('name', $search));
        }

        $result = $this->productRepository->search($criteria, $context);

        $products = [];
        foreach ($result->getEntities() as $product) {
            $products[] = [
                'id' => $product->getId(),
                'productNumber' => $product->getProductNumber(),
                'name' => $product->getName() ?? $product->getTranslation('name') ?? 'Ohne Name',
                'description' => mb_substr($product->getDescription() ?? '', 0, 100),
                'active' => $product->getActive(),
                'translationCount' => $product->getTranslations() ? $product->getTranslations()->count() : 0,
            ];
        }

        return new JsonResponse([
            'products' => $products,
            'total' => $result->getTotal(),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route(
        path: '/api/_action/vivatura-translator/translate-product/{productId}',
        name: 'api.action.vivatura_translator.translate_product',
        defaults: ['_acl' => []],
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
            $jobId = $this->createTranslationJob('product', $productId, $targetLanguageIds, $context);
            $this->messageBus->dispatch(new TranslateProductMessage($jobId, $productId, $targetLanguageIds));

            return new JsonResponse([
                'success' => true,
                'async' => true,
                'jobId' => $jobId,
                'message' => 'Translation job queued'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(
        path: '/api/_action/vivatura-translator/translate-products',
        name: 'api.action.vivatura_translator.translate_products',
        defaults: ['_acl' => []],
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

        $jobIds = [];
        foreach ($productIds as $productId) {
            try {
                $jobId = $this->createTranslationJob('product', $productId, $targetLanguageIds, $context);
                $this->messageBus->dispatch(new TranslateProductMessage($jobId, $productId, $targetLanguageIds));
                $jobIds[$productId] = $jobId;
            } catch (\Exception $e) {
                $jobIds[$productId] = ['error' => $e->getMessage()];
            }
        }

        return new JsonResponse([
            'success' => true,
            'async' => true,
            'jobIds' => $jobIds,
            'message' => count($productIds) . ' translation jobs queued'
        ]);
    }

    // ========================================
    // CMS PAGE ENDPOINTS
    // ========================================

    #[Route(
        path: '/api/_action/vivatura-translator/cms-pages',
        name: 'api.action.vivatura_translator.cms_pages',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function getCmsPages(Request $request, Context $context): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));
        $search = $request->query->get('search', '');

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset(($page - 1) * $limit);
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->addAssociation('translations');
        $criteria->addAssociation('sections.blocks.slots');

        if (!empty($search)) {
            $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter('name', $search));
        }

        $result = $this->cmsPageRepository->search($criteria, $context);

        $pages = [];
        foreach ($result->getEntities() as $cmsPage) {
            $slotCount = 0;
            $sections = $cmsPage->getSections();
            if ($sections) {
                foreach ($sections as $section) {
                    $blocks = $section->getBlocks();
                    if ($blocks) {
                        foreach ($blocks as $block) {
                            $slots = $block->getSlots();
                            if ($slots) {
                                $slotCount += $slots->count();
                            }
                        }
                    }
                }
            }

            $pages[] = [
                'id' => $cmsPage->getId(),
                'name' => $cmsPage->getName() ?? 'Ohne Name',
                'type' => $cmsPage->getType(),
                'slotCount' => $slotCount,
                'translationCount' => $cmsPage->getTranslations() ? $cmsPage->getTranslations()->count() : 0,
            ];
        }

        return new JsonResponse([
            'pages' => $pages,
            'total' => $result->getTotal(),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route(
        path: '/api/_action/vivatura-translator/translate-cms-page/{pageId}',
        name: 'api.action.vivatura_translator.translate_cms_page',
        defaults: ['_acl' => []],
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
            $jobId = $this->createTranslationJob('cms_page', $pageId, $targetLanguageIds, $context);
            $this->messageBus->dispatch(new TranslateCmsPageMessage($jobId, $pageId, $targetLanguageIds));

            return new JsonResponse([
                'success' => true,
                'async' => true,
                'jobId' => $jobId,
                'message' => 'Translation job queued'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(
        path: '/api/_action/vivatura-translator/translate-cms-pages',
        name: 'api.action.vivatura_translator.translate_cms_pages',
        defaults: ['_acl' => []],
        methods: ['POST']
    )]
    public function translateCmsPages(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $pageIds = $data['pageIds'] ?? [];
        $targetLanguageIds = $data['targetLanguageIds'] ?? [];

        if (empty($pageIds)) {
            return new JsonResponse(['error' => 'No CMS pages specified'], 400);
        }

        if (empty($targetLanguageIds)) {
            return new JsonResponse(['error' => 'No target languages specified'], 400);
        }

        $jobIds = [];
        foreach ($pageIds as $pageId) {
            try {
                $jobId = $this->createTranslationJob('cms_page', $pageId, $targetLanguageIds, $context);
                $this->messageBus->dispatch(new TranslateCmsPageMessage($jobId, $pageId, $targetLanguageIds));
                $jobIds[$pageId] = $jobId;
            } catch (\Exception $e) {
                $jobIds[$pageId] = ['error' => $e->getMessage()];
            }
        }

        return new JsonResponse([
            'success' => true,
            'async' => true,
            'jobIds' => $jobIds,
            'message' => count($pageIds) . ' translation jobs queued'
        ]);
    }

    // ========================================
    // SNIPPET ENDPOINTS
    // ========================================

    #[Route(
        path: '/api/_action/vivatura-translator/snippet-sets',
        name: 'api.action.vivatura_translator.snippet_sets',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function getSnippetSets(Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addAssociation('snippets');
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $result = $this->snippetSetRepository->search($criteria, $context);

        $sets = [];
        foreach ($result->getEntities() as $set) {
            $sets[] = [
                'id' => $set->getId(),
                'name' => $set->getName(),
                'iso' => $set->getIso(),
                'snippetCount' => $set->getSnippets() ? $set->getSnippets()->count() : 0,
            ];
        }

        return new JsonResponse(['snippetSets' => $sets]);
    }

    #[Route(
        path: '/api/_action/vivatura-translator/snippets',
        name: 'api.action.vivatura_translator.snippets',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function getSnippets(Request $request, Context $context): JsonResponse
    {
        $setId = $request->query->get('setId');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(500, max(1, (int) $request->query->get('limit', 100)));
        $search = $request->query->get('search', '');

        if (empty($setId)) {
            return new JsonResponse(['error' => 'No snippet set specified'], 400);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('setId', $setId));
        $criteria->setLimit($limit);
        $criteria->setOffset(($page - 1) * $limit);
        $criteria->addSorting(new FieldSorting('translationKey', FieldSorting::ASCENDING));

        if (!empty($search)) {
            $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter(
                \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter::CONNECTION_OR,
                [
                    new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter('translationKey', $search),
                    new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter('value', $search),
                ]
            ));
        }

        $result = $this->snippetRepository->search($criteria, $context);

        $snippets = [];
        foreach ($result->getEntities() as $snippet) {
            $snippets[] = [
                'id' => $snippet->getId(),
                'translationKey' => $snippet->getTranslationKey(),
                'value' => mb_substr($snippet->getValue() ?? '', 0, 100),
                'author' => $snippet->getAuthor(),
            ];
        }

        return new JsonResponse([
            'snippets' => $snippets,
            'total' => $result->getTotal(),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route(
        path: '/api/_action/vivatura-translator/translate-snippet-set',
        name: 'api.action.vivatura_translator.translate_snippet_set',
        defaults: ['_acl' => []],
        methods: ['POST']
    )]
    public function translateSnippetSet(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $sourceSetId = $data['sourceSetId'] ?? null;
        $targetSetId = $data['targetSetId'] ?? null;
        $snippetIds = $data['snippetIds'] ?? null;

        if (empty($sourceSetId) || empty($targetSetId)) {
            return new JsonResponse(['error' => 'Source and target snippet sets are required'], 400);
        }

        try {
            $entityId = $sourceSetId . ':' . $targetSetId;
            $jobId = $this->createTranslationJob('snippet_set', $entityId, [], $context);
            $this->messageBus->dispatch(new TranslateSnippetSetMessage($jobId, $sourceSetId, $targetSetId, $snippetIds));

            return new JsonResponse([
                'success' => true,
                'async' => true,
                'jobId' => $jobId,
                'message' => 'Translation job queued'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(
        path: '/api/_action/vivatura-translator/translate-snippet/{snippetId}',
        name: 'api.action.vivatura_translator.translate_snippet',
        defaults: ['_acl' => []],
        methods: ['POST']
    )]
    public function translateSnippet(string $snippetId, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $targetSetId = $data['targetSetId'] ?? null;

        if (empty($targetSetId)) {
            return new JsonResponse(['error' => 'No target snippet set specified'], 400);
        }

        try {
            $result = $this->translationService->translateSingleSnippet($snippetId, $targetSetId, $context);
            return new JsonResponse(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(
        path: '/api/_action/vivatura-translator/debug-models',
        name: 'api.action.vivatura_translator.debug_models',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function debugModels(Context $context): JsonResponse
    {
        try {
            // Use reflection or a public method to access the private AnthropicClient
            // Since we can't easily modify the service definition to make the client public,
            // we'll rely on the TranslationService if we added a method there, or check directly.

            // Actually, we need to access the client through the service.
            // Let's add a wrapper method to TranslationService first.

            // But since I cannot modify TranslationService in this step (Edit tool is for one file),
            // I will assume I can add a method to TranslationService later or just instantiate the client here for debugging?
            // No, better to add it to TranslationService properly.

            // Wait, I can't instantiate it easily because of dependencies.
            // I'll add a method getAvailableModels() to TranslationService that delegates to AnthropicClient.

            $models = $this->translationService->getAvailableModels();
            return new JsonResponse(['success' => true, 'models' => $models]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // TRANSLATION STATUS / PROGRESS
    // ========================================

    #[Route(
        path: '/api/_action/vivatura-translator/translation-status',
        name: 'api.action.vivatura_translator.translation_status',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function getTranslationStatus(Context $context): JsonResponse
    {
        $languages = $this->translationService->getAvailableLanguages($context);

        // Count products
        $productCriteria = new Criteria();
        $productCriteria->setLimit(1);
        $productTotal = $this->productRepository->search($productCriteria, $context)->getTotal();

        // Count CMS pages
        $cmsCriteria = new Criteria();
        $cmsCriteria->setLimit(1);
        $cmsTotal = $this->cmsPageRepository->search($cmsCriteria, $context)->getTotal();

        // Count snippet sets
        $snippetSetCriteria = new Criteria();
        $snippetSetTotal = $this->snippetSetRepository->search($snippetSetCriteria, $context)->getTotal();

        return new JsonResponse([
            'languages' => count($languages),
            'products' => $productTotal,
            'cmsPages' => $cmsTotal,
            'snippetSets' => $snippetSetTotal,
        ]);
    }

    // ========================================
    // JOB STATUS ENDPOINTS
    // ========================================

    #[Route(
        path: '/api/_action/vivatura-translator/job-status/{jobId}',
        name: 'api.action.vivatura_translator.job_status',
        defaults: ['_acl' => []],
        methods: ['GET']
    )]
    public function getJobStatus(string $jobId, Context $context): JsonResponse
    {
        $criteria = new Criteria([$jobId]);
        $job = $this->translationJobRepository->search($criteria, $context)->first();

        if (!$job) {
            return new JsonResponse(['error' => 'Job not found'], 404);
        }

        return new JsonResponse([
            'id' => $job->getId(),
            'type' => $job->getType(),
            'entityId' => $job->getEntityId(),
            'status' => $job->getStatus(),
            'result' => $job->getResult(),
            'startedAt' => $job->getStartedAt()?->format('c'),
            'finishedAt' => $job->getFinishedAt()?->format('c'),
            'createdAt' => $job->getCreatedAt()?->format('c'),
        ]);
    }

    #[Route(
        path: '/api/_action/vivatura-translator/jobs-status',
        name: 'api.action.vivatura_translator.jobs_status',
        defaults: ['_acl' => []],
        methods: ['POST']
    )]
    public function getJobsStatus(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $jobIds = $data['jobIds'] ?? [];

        if (empty($jobIds)) {
            return new JsonResponse(['error' => 'No job IDs specified'], 400);
        }

        $criteria = new Criteria($jobIds);
        $jobs = $this->translationJobRepository->search($criteria, $context);

        $results = [];
        foreach ($jobs->getEntities() as $job) {
            $results[$job->getId()] = [
                'type' => $job->getType(),
                'entityId' => $job->getEntityId(),
                'status' => $job->getStatus(),
                'result' => $job->getResult(),
                'startedAt' => $job->getStartedAt()?->format('c'),
                'finishedAt' => $job->getFinishedAt()?->format('c'),
            ];
        }

        return new JsonResponse(['jobs' => $results]);
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    private function createTranslationJob(string $type, string $entityId, array $targetLanguageIds, Context $context): string
    {
        $jobId = Uuid::randomHex();

        $this->translationJobRepository->create([
            [
                'id' => $jobId,
                'type' => $type,
                'entityId' => $entityId,
                'status' => 'pending',
                'targetLanguageIds' => $targetLanguageIds,
            ]
        ], $context);

        return $jobId;
    }
}
