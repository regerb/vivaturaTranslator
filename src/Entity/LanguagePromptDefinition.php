<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\Language\LanguageDefinition;

class LanguagePromptDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'viv_translator_language_prompt';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return LanguagePromptEntity::class;
    }

    public function getCollectionClass(): string
    {
        return LanguagePromptCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('language_id', 'languageId', LanguageDefinition::class))->addFlags(new Required()),
            new LongTextField('system_prompt', 'systemPrompt'),
            new ManyToOneAssociationField('language', 'language_id', LanguageDefinition::class, 'id', false),
        ]);
    }
}
