<?php declare(strict_types=1);

namespace Vivatura\VivTranslator\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(LanguagePromptEntity $entity)
 * @method void set(string $key, LanguagePromptEntity $entity)
 * @method LanguagePromptEntity[] getIterator()
 * @method LanguagePromptEntity[] getElements()
 * @method LanguagePromptEntity|null get(string $key)
 * @method LanguagePromptEntity|null first()
 * @method LanguagePromptEntity|null last()
 */
class LanguagePromptCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return LanguagePromptEntity::class;
    }
}
