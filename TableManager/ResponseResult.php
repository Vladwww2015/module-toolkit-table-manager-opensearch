<?php

namespace ModuleToolkit\TableManagerOpenSearch\TableManager;

use ModuleToolkit\TableManager\TableManager\IndexResponseResultInterface;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\SearchResultInterface;

class ResponseResult implements IndexResponseResultInterface
{
    public function __construct(
        protected SearchAdapter\ResponseFactory $responseFactory,
        protected SearchAdapter\DocumentFactory $documentFactory,
        protected SearchResultFactory $searchResultFactory
    ) {}

    public function getResponse(array $response): SearchResultInterface
    {
        if(!$response) {
            $searchResult = $this->searchResultFactory->create();
            $searchResult->setItems([]);
            $searchResult->setTotalCount(0);

            return $searchResult;
        }

        $response = $this->responseFactory->create([
            'documents' => $response['hits']['hits'],
            'aggregations' => $rawResponse['aggregations'] ?? [],
            'total' => $rawResponse['hits']['total']['value'] ?? 0
        ]);

        $searchResult = $this->searchResultFactory->create();
        $searchResult->setItems($response->getIterator()->getArrayCopy());
        $searchResult->setTotalCount($response->count());
        $searchResult->setAggregations($response->getAggregations());

        return $searchResult;
    }
}
