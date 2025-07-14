<?php
/**
 * MagoArab Order Export Collection Plugin
 * 
 * @category  MagoArab
 * @package   MagoArab_OrderExport
 * @author    MagoArab Team
 * @copyright Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderExport\Plugin\Sales\Model\ResourceModel\Order\Grid;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection as OrderGridCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Collection
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Add governorate filter and additional fields
     *
     * @param OrderGridCollection $subject
     * @param \Closure $proceed
     * @return OrderGridCollection
     */
    public function aroundLoad(OrderGridCollection $subject, \Closure $proceed)
    {
        if (!$subject->isLoaded()) {
            $this->addAdditionalFields($subject);
        }
        
        return $proceed();
    }

    /**
     * Add additional fields to collection
     *
     * @param OrderGridCollection $collection
     * @return void
     */
    protected function addAdditionalFields(OrderGridCollection $collection)
    {
        $enabled = $this->scopeConfig->getValue(
            'magoarab_orderexport/general/enabled',
            ScopeInterface::SCOPE_STORE
        );

        if (!$enabled) {
            return;
        }

        // Join with sales_order table for additional data
        $collection->getSelect()->joinLeft(
            ['so' => $collection->getTable('sales_order')],
            'main_table.entity_id = so.entity_id',
            ['customer_note' => 'so.customer_note']
        );

        // Join with sales_order_address for governorate
        $collection->getSelect()->joinLeft(
            ['soa' => $collection->getTable('sales_order_address')],
            'main_table.entity_id = soa.parent_id AND soa.address_type = "shipping"',
            ['governorate' => 'soa.region']
        );
    }
}