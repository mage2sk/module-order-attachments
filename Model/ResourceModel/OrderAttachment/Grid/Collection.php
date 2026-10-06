<?php
declare(strict_types=1);

namespace Panth\OrderAttachments\Model\ResourceModel\OrderAttachment\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected $_idFieldName = 'attachment_id';
    protected $aggregations;

    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactoryInterface $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        string $mainTable = 'panth_order_attachment',
        string $resourceModel = \Panth\OrderAttachments\Model\ResourceModel\OrderAttachment::class,
        ?string $identifierName = null,
        ?string $connectionName = null
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel, $identifierName, $connectionName);
    }

    protected function _initSelect(): static
    {
        parent::_initSelect();
        $this->addFilterToMap('attachment_id', 'main_table.attachment_id');
        return $this;
    }

    protected function _afterLoad(): static
    {
        parent::_afterLoad();
        foreach ($this->_items as $item) {
            if ($item->getData('attachment_id')) {
                $item->setId($item->getData('attachment_id'));
            }
        }
        return $this;
    }

    public function getAggregations() { return $this->aggregations; }
    public function setAggregations($aggregations) { $this->aggregations = $aggregations; return $this; }
    public function getSearchCriteria() { return null; }
    public function setSearchCriteria(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria) { return $this; }
    public function getTotalCount() { return $this->getSize(); }
    public function setTotalCount($totalCount) { return $this; }
    public function setItems(?array $items = null) { return $this; }
}
