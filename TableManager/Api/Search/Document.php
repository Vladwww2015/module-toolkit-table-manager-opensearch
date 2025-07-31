<?php

namespace JRP\TableManagerOpenSearch\TableManager\Api\Search;

use Magento\Framework\Api\Search\Document as BaseDocument;

class Document extends BaseDocument
{
    public function getData(string $key = '')
    {
        return $key ? $this->_get($key) : $this->_get();
    }
}
