<?php

namespace ModuleToolkit\TableManagerOpenSearch\TableManager;

use ModuleToolkit\TableManager\TableManager\GetConnectionNameInterface;
use ModuleToolkit\TableManager\TableManager\GetSearchEngineType;
use ModuleToolkit\TableManager\TableManager\IndexBuilderSearchInterface;
use ModuleToolkit\TableManager\TableManager\IndexResponseResultInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Psr\Log\LoggerInterface;
use OpenSearch\Exception\NotFoundHttpException;

class IndexBuilderSearch implements IndexBuilderSearchInterface
{
    private const REBUILD_MODE_POSTFIX = '_tmp';
    private const DEFAULT_BATCH_SIZE = 50000;
    private const DEFAULT_SORT_FIELD = 'entity_id';
    private const DEFAULT_SORT_DIRECTION = 'desc';

    protected \OpenSearch\Client $client;
    protected bool $isRebuildMode = false;
    protected string $aliasName;

    public function __construct(
        protected LoggerInterface $logger,
        protected ResourceConnection $resource,
        protected ConnectionManager $connectionManager,
        protected GetConnectionNameInterface $getConnectionName,
        protected IndexResponseResultInterface $indexResponseResult,
        private GetSearchEngineType $getSearchEngineType,
        protected BuildSelectQueryInterface $buildSelectQuery,
        protected string $indexName,
        protected string $indexTableName,
        protected string $primaryColumn,
        protected array $indexTableColumns,
        protected int $indexBatchSize = self::DEFAULT_BATCH_SIZE,
        string $aliasName = '',
    ) {
        if($this->getSearchEngineType->isOpenSearch()) {
            $this->client = $this->connectionManager->getConnection()->getOpenSearchClient();
        } else  {
            throw new \RuntimeException(
                "Failed to initialize OpenSearch client. " .
                "Please verify your OpenSearch connection configuration. "
            );
        }

        $this->aliasName = $aliasName ?: $this->indexName . '_alias';

        if ($this->aliasName === $this->indexName) {
            throw new \InvalidArgumentException("Alias name cannot be the same as index name!");
        }
    }

    public function getById(mixed $id, string $primaryKey = 'entity_id'): SearchResultInterface
    {
        try {
            $response = $this->client->get([
                'index' => $this->getIndexName(),
                'id' => $id
            ]);
            return $this->indexResponseResult->getResponse($response);
        } catch (NotFoundHttpException $e) {
            return $this->indexResponseResult->getResponse([]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to get document by ID: {$id}", ['exception' => $e]);
            throw $e;
        }
    }

    public function search(array $searchCriteria): SearchResultInterface
    {
        $params = [
            'index' => $this->getIndexName(),
            'body' => [
                'query' => $this->buildQuery($searchCriteria)
            ]
        ];

        try {
            $response = $this->client->search($params);
            return $this->indexResponseResult->getResponse($response);
        } catch (\Exception $e) {
            $this->logger->error("Search failed", ['criteria' => $searchCriteria, 'exception' => $e]);
            throw $e;
        }
    }

    public function rebuild(array $ids = []): void
    {
        $this->isRebuildMode = empty($ids);

        try {
            if ($this->isRebuildMode) {
                $this->prepareNewIndex();
            }

            $this->processDataBatch($ids);

            if ($this->isRebuildMode) {
                $this->finalizeRebuild();
            }
        } catch (\Exception $e) {
            $this->logger->error("Rebuild failed", ['exception' => $e]);
            throw $e;
        } finally {
            $this->isRebuildMode = false;
        }
    }

    public function getTotalCount(array $searchCriteria = []): int
    {
        try {
            $response = $this->client->count([
                'index' => $this->getIndexName(),
                'body' => [
                    'query' => $this->buildQuery($searchCriteria)
                ]
            ]);

            return $response['count'] ?? 0;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get total count", ['exception' => $e]);
            return 0;
        }
    }

    public function searchWithPagination(
        array $searchCriteria,
        int $page = 1,
        int $pageSize = 50,
        array $sort = [self::DEFAULT_SORT_FIELD => self::DEFAULT_SORT_DIRECTION]
    ): SearchResultInterface {
        $params = [
            'index' => $this->getIndexName(),
            'body' => [
                'from' => ($page - 1) * $pageSize,
                'size' => $pageSize,
                'query' => $this->buildQuery($searchCriteria),
                'sort' => $this->normalizeSortFields($sort)
            ]
        ];

        try {
            $response = $this->client->search($params);
            return $this->indexResponseResult->getResponse($response);
        } catch (\Exception $e) {
            $this->logger->error("Pagination search failed", [
                'page' => $page,
                'pageSize' => $pageSize,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    public function getIndexName(): string
    {
        return $this->isRebuildMode
            ? $this->getTempIndexName()
            : $this->getAliasName();
    }

    protected function getAliasName(): string
    {
        return $this->aliasName;
    }

    protected function getTempIndexName(): string
    {
        return $this->indexName . self::REBUILD_MODE_POSTFIX;
    }

    private function prepareNewIndex(): void
    {
        $tempIndex = $this->getTempIndexName();

        try {
            if ($this->client->indices()->exists(['index' => $tempIndex])) {
                $this->client->indices()->delete(['index' => $tempIndex]);
            }
            $this->client->indices()->create([
                'index' => $tempIndex,
                'body' => [
                    'settings' => $this->getIndexSettings(),
                    'mappings' => [
                        'properties' => [
                            'entity_id' => [
                                'type' => 'keyword'
                            ],
                        ]
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to prepare new index", ['index' => $tempIndex, 'exception' => $e]);
            throw $e;
        }
    }

    private function processDataBatch(array $ids = []): void
    {
        $connection = $this->resource->getConnection($this->getConnectionName->get());
        $table = $this->resource->getTableName($this->indexTableName);
        $existingIds = [];
        $page = 0;

        if($this->buildSelectQuery->getMode() === Mode::GENERATOR){
            $batch = [];
            foreach ($this->buildSelectQuery->getData(
                $connection,
                $table,
                $this->indexTableColumns,
                $this->primaryColumn,
                $ids,
            ) as $data) {
                $batch[] = $data;
                if(count($batch) > 1000){
                    $this->processBatch($batch, $existingIds);
                    $batch = [];
                }
            }
            if(count($batch)){
                $this->processBatch($batch, $existingIds);
            }
            return;
        }

        if($this->buildSelectQuery->getMode() === Mode::DEFAULT) {
            do {

                $data = $connection->fetchAll($this->buildSelectQuery->getSelectQuery(
                    $connection,
                    $table,
                    $this->indexBatchSize,
                    $this->indexTableColumns,
                    $this->primaryColumn,
                    $ids,
                    $page
                ));

                if (!empty($data)) {
                    $this->processBatch($data, $existingIds);
                }

                $page++;
            } while (count($data) === $this->indexBatchSize && empty($ids));

            if (!empty($ids)) {
                $this->handleMissingIds($ids, $existingIds);
            }

            return;
        }

        throw new \Exception('Index Builder Search Failed. Mode does not exist.');
    }


    private function processBatch(array $data, array &$existingIds): void
    {
        $bulk = ['body' => []];

        foreach ($data as $row) {
            $existingIds[] = $row[$this->primaryColumn];

            $bulk['body'][] = [
                'index' => [
                    '_index' => $this->getIndexName(),
                    '_id' => $row[$this->primaryColumn]
                ]
            ];
            $bulk['body'][] = $row;
        }

        try {
            $this->client->bulk($bulk);
        } catch (\Exception $e) {
            $this->logger->error("Bulk index failed", ['exception' => $e]);
            throw $e;
        }
    }

    private function handleMissingIds(array $requestedIds, array $existingIds): void
    {
        $missingIds = array_diff($requestedIds, $existingIds);

        if (empty($missingIds)) {
            return;
        }

        $bulk = ['body' => []];
        foreach ($missingIds as $id) {
            $bulk['body'][] = [
                'delete' => [
                    '_index' => $this->getIndexName(),
                    '_id' => $id
                ]
            ];
        }

        try {
            $this->client->bulk($bulk);
        } catch (\Exception $e) {
            $this->logger->error("Failed to delete missing IDs", ['ids' => $missingIds, 'exception' => $e]);
        }
    }

    private function finalizeRebuild(): void
    {
        $this->switchAliasToNewIndex();
        $this->cleanupOldIndex();
    }

    private function switchAliasToNewIndex(): void
    {
        $alias = $this->getAliasName();
        $newIndex = $this->getTempIndexName();
        $oldIndex = $this->getCurrentAliasedIndex();

        if ($this->client->indices()->exists(['index' => $alias])) {
            $indexMeta = $this->client->indices()->get(['index' => $alias]);

            if (!isset($indexMeta[$alias]['aliases']) || empty($indexMeta[$alias]['aliases'])) {
                $msg = sprintf("Cannot use alias [%s]: index with this name already exists.", $alias);
                $this->logger->error($msg);
                $this->client->indices()->delete(['index' => $alias]);
            }
        }

        $actions = [];

        if ($oldIndex) {
            $actions[] = ['remove' => ['index' => $oldIndex, 'alias' => $alias]];
        }

        $actions[] = ['add' => ['index' => $newIndex, 'alias' => $alias]];

        try {
            $this->client->indices()->updateAliases(['body' => ['actions' => $actions]]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to update aliases", [
                'actions' => $actions,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    private function getCurrentAliasedIndex(): ?string
    {
        try {
            $aliases = $this->client->indices()->getAlias(['name' => $this->getAliasName()]);
            return !empty($aliases) ? array_key_first($aliases) : null;
        } catch (NotFoundHttpException $e) {
            return null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get current aliased index", ['exception' => $e]);
            return null;
        }
    }

    private function cleanupOldIndex(): void
    {
        $oldIndex = $this->getCurrentAliasedIndex();
        $newIndex = $this->getTempIndexName();

        if ($oldIndex && $oldIndex !== $newIndex) {
            try {
                $this->client->indices()->delete(['index' => $oldIndex]);
            } catch (\Exception $e) {
                $this->logger->error("Failed to delete old index", ['index' => $oldIndex, 'exception' => $e]);
            }
        }
    }

    protected function buildQuery(array $searchCriteria): array
    {
        $must = [];
        $filter = [];

        foreach ($searchCriteria as $field => $criterion) {
            if (!is_array($criterion) || !isset($criterion['value'])) {
                $criterion = ['condition' => 'EQ', 'value' => $criterion];
            }

            $condition = strtoupper($criterion['condition'] ?? 'EQ');
            $value = $criterion['value'];

            switch (strtoupper($condition)) {
                case 'LIKE':

                    $must[] = [
                        'wildcard' => [
                            $this->normalizeFieldName($field) => [
                                'value' => strtolower(str_replace('%', '*', $value)) . '*',
                                'case_insensitive' => true,
                            ]
                        ]
                    ];
                    break;

                case 'EQ':
                    $filter[] = ['term' => [$field => $value]];
                    break;

                case 'IN':
                    $filter[] = ['terms' => [$field => (array)$value]];
                    break;

                case 'GT':
                case 'GTE':
                case 'LT':
                case 'LTE':
                    $opMap = ['GT' => 'gt', 'GTE' => 'gte', 'LT' => 'lt', 'LTE' => 'lte'];
                    $must[] = ['range' => [$field => [$opMap[$condition] => $value]]];
                    break;

                default:
                    $filter[] = ['term' => [$field => $value]];
            }
        }

        $bool = [];
        if ($must)   { $bool['must'] = $must; }
        if ($filter) { $bool['filter'] = $filter; }

        return ['bool' => $bool];
    }

    protected function normalizeSortFields(array $sort): array
    {
        $normalized = [];

        foreach ($sort as $field => $direction) {
            $normalized[$this->normalizeFieldName($field)] = [
                'order' => strtolower($direction) === 'desc' ? 'desc' : 'asc'
            ];
        }

        return $normalized;
    }

    protected function normalizeFieldName(string $field): string
    {
        if (in_array($field, ['entity_id'])) {
            return $field;
        }

        return str_ends_with($field, '.keyword') ? $field : $field . '.keyword';
    }

    protected function getIndexSettings(): array
    {
        return [
            'number_of_shards' => 1,
            'number_of_replicas' => 1,
            'analysis' => [
                'analyzer' => [
                    'default' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding']
                    ]
                ]
            ]
        ];
    }

}
