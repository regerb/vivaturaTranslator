<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(TranslationJobEntity $entity)
 * @method void set(string $key, TranslationJobEntity $entity)
 * @method TranslationJobEntity[] getIterator()
 * @method TranslationJobEntity[] getElements()
 * @method TranslationJobEntity|null get(string $key)
 * @method TranslationJobEntity|null first()
 * @method TranslationJobEntity|null last()
 */
class TranslationJobCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return TranslationJobEntity::class;
    }
}
