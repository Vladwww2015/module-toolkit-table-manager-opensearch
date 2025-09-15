<?php

namespace ModuleToolkit\TableManagerOpenSearch\TableManager;

class BuildSelectQuery implements BuildSelectQueryInterface
{
    /**
     * @param Mode $mode
     */
    public function __construct(
        protected Mode $mode = Mode::DEFAULT
    ){}

    /**
     * @return Mode
     */
    public function getMode(): Mode
    {
        return $this->mode;
    }

     public function getSelectQuery(
         \Magento\Framework\DB\Adapter\AdapterInterface $connection,
         string $table,
         int $indexBatchSize,
         array $indexTableColumns,
         string $primaryColumn,
         array $ids,
         int $page
     ): \Magento\Framework\DB\Select {
         $select = $connection->select()
             ->from($table, array_unique([...$indexTableColumns, $primaryColumn]));

         if (!empty($ids)) {
             $select->where($primaryColumn . ' IN (?)', $ids);
         } else {
             $select->limit($indexBatchSize, $page * $indexBatchSize);
         }

         return $select;
     }

    public function getData(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $table,
        array $indexTableColumns,
        string $primaryColumn,
        array $ids
    ): \Generator
    {
        throw new \Exception('Not implemented');  
    } 
}
