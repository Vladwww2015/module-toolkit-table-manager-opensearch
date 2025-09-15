### How to use.

--- di.xml
```xml
<virtualType name="Custom\Module\Model\GetConnectionName"
             type="ModuleToolkit\TableManager\TableManager\GetConnectionName">
    <arguments>
        <argument name="connectionNameConfigPath" xsi:type="string">default</argument>
    </arguments>
</virtualType>

<virtualType name="Custom\Module\Model\CustomIndexBuilderSearch"
             type="ModuleToolkit\TableManagerOpenSearch\TableManager\IndexBuilderSearch"
>
    <arguments>
        <argument name="getConnectionName" xsi:type="object">Custom\Module\Model\GetConnectionName</argument>
        <argument name="indexName" xsi:type="string">custom_index_name</argument>
        <argument name="indexTableName" xsi:type="string">custom_index_table</argument>
        <argument name="primaryColumn" xsi:type="string">entity_id</argument>
        <argument name="indexTableColumns" xsi:type="array">
            <item name="entity_id" xsi:type="string">entity_id</item>
            <item name="name" xsi:type="string">name</item>
            <item name="total" xsi:type="string">total</item>
        </argument>
    </arguments>
</virtualType>
<type name="Custom\Module\Model\Indexer\CustomIndexer">
    <arguments>
        <argument name="indexBuilder" xsi:type="object">Custom\Module\Model\CustomIndexBuilderSearch</argument>
    </arguments>
</type>

```

- create indexer.xml
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Indexer/etc/indexer.xsd">
    <indexer id="custom_unique_name_index" view_id="custom_unique_name_index"
             class="Module\Custom\Model\Indexer\CustomNameIndexer" shared_index="custom_unique_name_index">
        <title translate="true">Custom Name-Title</title>
        <description translate="true">Custom Name-Description</description>
    </indexer>
</config>
```

- create mview.xml
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Mview/etc/mview.xsd">
    <view id="custom_unique_name_index" class="Module\Custom\Model\Indexer\CustomNameIndexer"
          group="indexer">
        <subscriptions>
            <table name="custom_table_name" entity_column="entity_id"/>
        </subscriptions>
    </view>
</config>

```


- Index process
```php
namespace Custom\Module\Model\Indexer;

use ModuleToolkit\TableManager\TableManager\IndexBuilderSearchInterface;
use Magento\Framework\Indexer\ActionInterface;


class CustomIndexer implements ActionInterface
{
    public function __construct(protected IndexBuilderSearchInterface $indexBuilder)
    {}

    public function execute($ids = null)
    {
        $this->indexBuilder->rebuild($ids);
    }

    public function executeFull()
    {
        $this->indexBuilder->rebuild();
    }

    public function executeList(array $ids)
    {
        $this->indexBuilder->rebuild($ids);
    }

    public function executeRow($id)
    {
        $this->indexBuilder->rebuild([$id]);
    }
}

```

- 1. Search and get OpenSearch Results Format
```php
use ModuleToolkit\TableManager\TableManager\IndexBuilderSearchInterface;

class CustomSearch
{
    public function __construct(
        protected IndexBuilderSearchInterface $indexBuilderSearch
    )
    {
    }
    
    public function search()
    {
        $searchCriteria = ['sku' => 'test'];
        
        $page = 1;
        $size = 300;
        $sort = ['sku' => 'DESC']
    
        $this->indexBuilderSearch->searchWithPagination($searchCriteria, $page, $size, $sort)//array;
        $this->indexBuilderSearch->search($searchCriteria);// array
        $this->indexBuilderSearch->getById(1); //array
    }
}

```

- 2. Search and get magento array Format
```php
use ModuleToolkit\TableManager\TableManager\IndexBuilderSearchInterface;
use ModuleToolkit\TableManager\TableManager\TableManagerInterface;

class CustomSearch
{
    public function __construct(
        protected IndexBuilderSearchInterface $indexBuilderSearch,
        protected TableManagerInterface $tableManager
    ){}
    
    public function search()
    {
        $searchCriteria = ['sku' => 'test'];
        
        $page = 1;
        $size = 300;
        $sort = ['sku' => 'DESC']
        
        $tableRequest = $this->tableManager->getTableRequest();
        $tableRequest
            ->setCurPage($page)
            ->setLimit($size)
            ->setSortOrder($sort)
            ->setValues($searchCriteria);
            
        $this->tableManager->indexFastSearch($tableRequest, $this->indexBuilderSearch); //array magento array format
    }
}


```
