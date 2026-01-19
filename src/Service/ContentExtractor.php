<?php declare(strict_types=1);

namespace Vivatura\VivTranslator\Service;

use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\Snippet\SnippetEntity;

class ContentExtractor
{
    /**
     * Translatable product fields
     */
    private const PRODUCT_FIELDS = [
        'name',
        'description',
        'metaTitle',
        'metaDescription',
        'keywords',
        'packUnit',
        'packUnitPlural',
    ];

    /**
     * Extract translatable content from a product
     *
     * @param ProductEntity $product
     * @return array<string, string|null>
     */
    public function extractProductContent(ProductEntity $product): array
    {
        $content = [];

        foreach (self::PRODUCT_FIELDS as $field) {
            $getter = 'get' . ucfirst($field);
            if (method_exists($product, $getter)) {
                $value = $product->$getter();
                if (!empty($value)) {
                    $content[$field] = $value;
                }
            }
        }

        // Extract translatable custom fields
        $customFields = $product->getCustomFields();
        if (!empty($customFields)) {
            foreach ($customFields as $key => $value) {
                if (is_string($value) && !empty($value) && $this->isTranslatableCustomField($key)) {
                    $content['customFields.' . $key] = $value;
                }
            }
        }

        return $content;
    }

    /**
     * Extract translatable content from a CMS page
     *
     * @param CmsPageEntity $page
     * @return array<string, string|null>
     */
    public function extractCmsPageContent(CmsPageEntity $page): array
    {
        $content = [];

        // Basic page fields
        if (!empty($page->getName())) {
            $content['name'] = $page->getName();
        }

        // Extract content from sections -> blocks -> slots
        $sections = $page->getSections();
        if ($sections === null) {
            return $content;
        }

        $slotIndex = 0;
        foreach ($sections as $section) {
            $blocks = $section->getBlocks();
            if ($blocks === null) continue;

            foreach ($blocks as $block) {
                $slots = $block->getSlots();
                if ($slots === null) continue;

                foreach ($slots as $slot) {
                    $slotContent = $this->extractSlotContent($slot, $slotIndex);
                    $content = array_merge($content, $slotContent);
                    $slotIndex++;
                }
            }
        }

        return $content;
    }

    /**
     * Extract content from a CMS slot
     */
    private function extractSlotContent($slot, int $index): array
    {
        $content = [];
        $config = $slot->getConfig();
        $slotType = $slot->getType();

        if (empty($config)) {
            return $content;
        }

        // Text-based slot types
        $textFields = ['content', 'title', 'headline', 'text', 'label', 'altText', 'linkText'];
        
        foreach ($textFields as $field) {
            if (isset($config[$field]['value']) && !empty($config[$field]['value'])) {
                $value = $config[$field]['value'];
                if (is_string($value) && !$this->isMediaUrl($value)) {
                    $content["slot_{$index}_{$field}"] = $value;
                }
            }
        }

        return $content;
    }

    /**
     * Extract content from a snippet
     *
     * @param SnippetEntity $snippet
     * @return array<string, string>
     */
    public function extractSnippetContent(SnippetEntity $snippet): array
    {
        $value = $snippet->getValue();
        if (empty($value)) {
            return [];
        }

        return ['value' => $value];
    }

    /**
     * Check if a custom field key indicates a translatable field
     * Typically fields ending with specific suffixes or having certain prefixes
     */
    private function isTranslatableCustomField(string $key): bool
    {
        // Skip IDs, numbers, dates, booleans typically stored in custom fields
        $skipPatterns = ['_id', '_date', '_number', '_bool', '_at', '_count'];
        
        foreach ($skipPatterns as $pattern) {
            if (str_ends_with(strtolower($key), $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a value is a media URL that shouldn't be translated
     */
    private function isMediaUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') 
            || str_starts_with($value, 'https://') 
            || str_starts_with($value, '/media/')
            || preg_match('/\.(jpg|jpeg|png|gif|svg|webp|mp4|pdf)$/i', $value);
    }
}
