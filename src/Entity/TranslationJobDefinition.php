<?php declare(strict_types=1);

namespace Vivatura\VivaturaTranslator\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class TranslationJobDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'viv_translator_job';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return TranslationJobEntity::class;
    }

    public function getCollectionClass(): string
    {
        return TranslationJobCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new StringField('type', 'type'))->addFlags(new Required()),
            (new StringField('entity_id', 'entityId'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            new JsonField('target_language_ids', 'targetLanguageIds'),
            new JsonField('result', 'result'),
            new DateTimeField('started_at', 'startedAt'),
            new DateTimeField('finished_at', 'finishedAt'),
        ]);
    }
}
