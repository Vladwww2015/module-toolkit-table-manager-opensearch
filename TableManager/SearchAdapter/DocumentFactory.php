<?php

namespace ModuleToolkit\TableManagerOpenSearch\TableManager\SearchAdapter;

use Magento\Framework\Search\EntityMetadata;
use ModuleToolkit\TableManagerOpenSearch\TableManager\Api\Search\Document;
use Magento\Elasticsearch\SearchAdapter\DocumentFactory as BaseDocumentFactory;

class DocumentFactory extends BaseDocumentFactory
{
    protected EntityMetadata $entityMetadata;

    public function __construct(
        EntityMetadata $entityMetadata
    ) {
        parent::__construct($entityMetadata);

        $this->entityMetadata = $entityMetadata;
    }

    public function create($rawDocument)
    {
        $attributes = [];
        $sourceData = $rawDocument['_source'] ?? [];

        foreach (array_merge($rawDocument, $sourceData) as $fieldName => $value) {
            if ($fieldName !== '_source' && $fieldName !== '_index') {
                $attributes[$fieldName] = $value;
            }
        }

        return new Document([
            ...$attributes
        ]);
    }
}
