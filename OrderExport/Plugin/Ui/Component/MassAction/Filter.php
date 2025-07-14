<?php
namespace MagoArab\OrderExport\Plugin\Ui\Component\MassAction;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Ui\Component\MassAction\Filter as MassActionFilter;

class Filter
{
    /**
     * @var \MagoArab\OrderExport\Plugin\Ui\Model\Export\ConvertToCsv
     */
    private $convertToCsv;

    public function __construct(
        \MagoArab\OrderExport\Plugin\Ui\Model\Export\ConvertToCsv $convertToCsv
    ) {
        $this->convertToCsv = $convertToCsv;
    }

    /**
     * @param MassActionFilter $subject
     * @param AbstractDb $result
     * @return AbstractDb
     */
    public function afterGetCollection(MassActionFilter $subject, $result)
    {
        // Store the collection for use in export
        $this->convertToCsv->setFilteredCollection($result);
        return $result;
    }
}