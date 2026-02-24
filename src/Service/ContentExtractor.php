<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\Snippet\SnippetEntity;

class ContentExtractor
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }
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
     * Top-level CMS slot fields that can contain translatable text.
     * Some fields (e.g. items/entries) often store nested arrays (FAQ/accordion elements).
     */
    private const CMS_TEXT_FIELDS = [
        'content',
        'title',
        'subtitle',
        'subline',
        'headline',
        'text',
        'description',
        'label',
        'altText',
        'linkText',
        'buttonText',
        'caption',
        'confirmationText',
        'iframeTitle',
        'items',
        'entries',
        'accordionItems',
        'faqItems',
        'questions',
        'answers',
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

        if (empty($config) || !is_array($config)) {
            return $content;
        }

        foreach (self::CMS_TEXT_FIELDS as $field) {
            if (!array_key_exists($field, $config)) {
                continue;
            }

            // Standard Shopware structure: config[field][value]
            if (is_array($config[$field]) && array_key_exists('value', $config[$field])) {
                $this->extractFieldValue($content, $field, $config[$field]['value'], $index);
                continue;
            }

            // Direct field value used by some custom elements
            $this->extractFieldValue($content, $field, $config[$field], $index);
        }

        return $content;
    }

    /**
     * @param array<string, string> $content
     */
    private function extractFieldValue(array &$content, string $field, mixed $value, int $slotIndex): void
    {
        if (is_string($value) && $this->isTranslatableCmsString($value)) {
            $content["slot_{$slotIndex}_{$field}"] = $value;
            return;
        }

        if (!is_array($value)) {
            return;
        }

        $nestedStrings = $this->extractNestedCmsStrings($value);
        foreach ($nestedStrings as $path => $nestedValue) {
            $content["slot_{$slotIndex}_{$field}__{$path}"] = $nestedValue;
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<string, string>
     */
    private function extractNestedCmsStrings(array $value, string $pathPrefix = ''): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $pathSegment = (string) $key;
            $path = $pathPrefix === '' ? $pathSegment : $pathPrefix . '.' . $pathSegment;

            if (is_array($item)) {
                $result = array_merge($result, $this->extractNestedCmsStrings($item, $path));
                continue;
            }

            if (is_string($item) && $this->isTranslatableCmsString($item)) {
                $result[$path] = $item;
            }
        }

        return $result;
    }

    private function isTranslatableCmsString(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || $this->isMediaUrl($trimmed)) {
            return false;
        }

        // Skip purely numeric values and values without any letters.
        if (is_numeric($trimmed) || !preg_match('/\p{L}/u', $trimmed)) {
            return false;
        }

        return true;
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
