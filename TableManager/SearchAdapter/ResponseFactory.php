<?php

namespace JRP\TableManagerOpenSearch\TableManager\SearchAdapter;

use Magento\Elasticsearch\SearchAdapter\AggregationFactory;
use Magento\Elasticsearch\SearchAdapter\ResponseFactory as BaseResponseFactory;
use Magento\Framework\ObjectManagerInterface;

class ResponseFactory extends BaseResponseFactory
{
    public function __construct(
        ObjectManagerInterface $objectManager,
        DocumentFactory $documentFactory,
        AggregationFactory $aggregationFactory
    ) {
        parent::__construct($objectManager, $documentFactory, $aggregationFactory);
    }
}
