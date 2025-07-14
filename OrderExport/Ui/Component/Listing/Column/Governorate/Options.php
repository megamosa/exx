<?php
/**
 * MagoArab Order Export Governorate Options
 * 
 * @category  MagoArab
 * @package   MagoArab_OrderExport
 * @author    MagoArab Team
 * @copyright Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderExport\Ui\Component\Listing\Column\Governorate;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Framework\App\ResourceConnection;

class Options implements OptionSourceInterface
{
    /**
     * @var CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var array
     */
    protected $options;

    /**
     * @param CollectionFactory $orderCollectionFactory
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        CollectionFactory $orderCollectionFactory,
        ResourceConnection $resourceConnection
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        if ($this->options === null) {
            $this->options = [['value' => '', 'label' => __('جميع المحافظات')]];
            
            try {
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()
                    ->from(
                        ['soa' => $this->resourceConnection->getTableName('sales_order_address')],
                        ['region']
                    )
                    ->where('soa.address_type = ?', 'shipping')
                    ->where('soa.region IS NOT NULL')
                    ->where('soa.region != ?', '')
                    ->group('soa.region')
                    ->order('soa.region ASC');
                
                $regions = $connection->fetchCol($select);
                
                foreach ($regions as $region) {
                    if (!empty(trim($region))) {
                        $this->options[] = [
                            'value' => $region,
                            'label' => $region
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Log error but don't break functionality
                $this->options[] = ['value' => 'error', 'label' => 'خطأ في تحميل المحافظات'];
            }
        }
        
        return $this->options;
    }
}