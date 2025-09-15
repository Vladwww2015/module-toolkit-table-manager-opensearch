<?php

namespace ModuleToolkit\TableManagerOpenSearch\TableManager;

interface BuildSelectQueryInterface
{
    public function getMode(): Mode;
    
    public function getData(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $table,
        array $indexTableColumns,
        string $primaryColumn,
        array $ids
    ): \Generator;

    public function getSelectQuery(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $table,
        int $indexBatchSize,
        array $indexTableColumns,
        string $primaryColumn,
        array $ids,
        int $page
    ): \Magento\Framework\DB\Select;
}
