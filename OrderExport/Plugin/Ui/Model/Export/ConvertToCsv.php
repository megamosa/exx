<?php

namespace MagoArab\OrderExport\Plugin\Ui\Model\Export;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Ui\Model\Export\ConvertToCsv as OriginalConvertToCsv;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Framework\Api\SearchCriteriaInterface;
// Remove the duplicate line 18: use Magento\Framework\Exception\LocalizedException;

class ConvertToCsv
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var Filter
     */
    protected $filter;

    /**
     * Constructor
     *
     * @param Filesystem $filesystem
     * @param ScopeConfigInterface $scopeConfig
     * @param ResourceConnection $resourceConnection
     * @param Filter $filter
     */
    public function __construct(
        Filesystem $filesystem,
        ScopeConfigInterface $scopeConfig,
        ResourceConnection $resourceConnection,
        Filter $filter
    ) {
        $this->filesystem = $filesystem;
        $this->scopeConfig = $scopeConfig;
        $this->resourceConnection = $resourceConnection;
        $this->filter = $filter;
    }

    /**
     * Around getCsvFile
     *
     * @param \Magento\Ui\Model\Export\ConvertToCsv $subject
     * @param callable $proceed
     * @return array
     */
    public function aroundGetCsvFile($subject, callable $proceed)
    {
        // Add logging to check if plugin is called
        error_log("MagoArab OrderExport Plugin: aroundGetCsvFile called");
        
        // Check if enhanced export is enabled
        $isEnabled = $this->isEnhancedExportEnabled();
        error_log("MagoArab OrderExport Plugin: Enhanced export enabled = " . ($isEnabled ? 'YES' : 'NO'));
        
        if (!$isEnabled) {
            error_log("MagoArab OrderExport Plugin: Using default export");
            return $proceed();
        }

        try {
            error_log("MagoArab OrderExport Plugin: Using custom export");
            return $this->generateExcelCompatibleCsv($subject);
        } catch (\Exception $e) {
            error_log("MagoArab OrderExport Plugin Error: " . $e->getMessage());
            // Fallback to original method if there's an error
            return $proceed();
        }
    }

    /**
     * Check if enhanced export is enabled
     *
     * @return bool
     */
    protected function isEnhancedExportEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            'magoarab_orderexport/general/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if product details should be included
     *
     * @return bool
     */
    protected function isProductDetailsEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            'magoarab_orderexport/product_details/include_product_details',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if a specific column is enabled
     *
     * @param string $columnKey
     * @return bool
     */
    protected function isColumnEnabled($columnKey)
    {
        return $this->scopeConfig->isSetFlag(
            'magoarab_orderexport/columns/' . $columnKey,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Check if a specific product column is enabled
     *
     * @param string $columnKey
     * @return bool
     */
    protected function isProductColumnEnabled($columnKey)
    {
        return $this->scopeConfig->isSetFlag(
            'magoarab_orderexport/product_details/' . $columnKey,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Generate Excel compatible CSV
     *
     * @param \Magento\Ui\Model\Export\ConvertToCsv $subject
     * @return array
     */
    protected function generateExcelCompatibleCsv($subject)
    {
        $fileName = $this->createExcelCompatibleCsvFile();
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $stream = $directory->openFile($fileName, 'w+');
        $stream->lock();
        
        // Write BOM for Excel UTF-8 compatibility
        $stream->write("\xEF\xBB\xBF");
        
        // Write headers
        $this->writeExcelCompatibleHeaders($stream);
        
        // Process data in batches
        $this->processBatchesForExcel($subject, $stream);
        
        $stream->unlock();
        $stream->close();
        
        return [
            'type' => 'filename',
            'value' => $fileName,
            'rm' => true
        ];
    }

    /**
     * Process batches for Excel export
     *
     * @param \Magento\Ui\Model\Export\ConvertToCsv $subject
     * @param \Magento\Framework\Filesystem\File\WriteInterface $stream
     * @return void
     */
    protected function processBatchesForExcel($subject, $stream)
    {
        $connection = $this->resourceConnection->getConnection();
        
        // Set connection encoding to UTF-8
        $connection->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $batchSize = 100;
        $currentPage = 1;
        
        do {
            $offset = ($currentPage - 1) * $batchSize;
            
            // Build optimized query
            $select = $this->buildOptimizedQuery($connection, $offset, $batchSize);
            
            // Apply any additional filters if needed
            $this->applyAdditionalFilters($select, $connection);
            
            $orders = $connection->fetchAll($select);
            
            foreach ($orders as $order) {
                $row = $this->buildExcelCompatibleOrderRow($order, $connection);
                $this->writeExcelCompatibleRow($stream, $row);
            }
            
            $currentPage++;
        } while (count($orders) === $batchSize);
    }

    /**
     * Apply additional filters to the select query
     *
     * @param \Magento\Framework\DB\Select $select
     * @param AdapterInterface $connection
     * @return void
     */
    protected function applyAdditionalFilters($select, $connection)
    {
        // Get filter parameters from request if available
        $request = \Magento\Framework\App\ObjectManager::getInstance()
            ->get('\Magento\Framework\App\RequestInterface');
        
        // Apply date filters if present
        $fromDate = $request->getParam('created_at_from');
        $toDate = $request->getParam('created_at_to');
        
        if ($fromDate) {
            $select->where('main_table.created_at >= ?', $fromDate . ' 00:00:00');
        }
        
        if ($toDate) {
            $select->where('main_table.created_at <= ?', $toDate . ' 23:59:59');
        }
        
        // Apply status filter if present
        $status = $request->getParam('status');
        if ($status) {
            if (is_array($status)) {
                $select->where('main_table.status IN (?)', $status);
            } else {
                $select->where('main_table.status = ?', $status);
            }
        }
        
        // Apply store filter if present
        $storeId = $request->getParam('store_id');
        if ($storeId !== null && $storeId !== '') {
            $select->where('main_table.store_id = ?', $storeId);
        }
    }

    /**
     * Map UI grid field names to database column names
     *
     * @param string $field
     * @return string|null
     */
    protected function mapFieldToDbColumn($field)
    {
        $fieldMap = [
            'increment_id' => 'main_table.increment_id',
            'status' => 'main_table.status',
            'created_at' => 'main_table.created_at',
            'updated_at' => 'main_table.updated_at',
            'grand_total' => 'main_table.grand_total',
            'base_grand_total' => 'main_table.base_grand_total',
            'customer_email' => 'main_table.customer_email',
            'customer_firstname' => 'main_table.customer_firstname',
            'customer_lastname' => 'main_table.customer_lastname',
            'billing_name' => 'main_table.billing_name',
            'shipping_name' => 'main_table.shipping_name',
            'store_id' => 'main_table.store_id',
            'entity_id' => 'main_table.entity_id'
        ];
        
        return $fieldMap[$field] ?? null;
    }

    /**
     * Build optimized query for order export
     *
     * @param AdapterInterface $connection
     * @param int $offset
     * @param int $limit
     * @return \Magento\Framework\DB\Select
     */
    protected function buildOptimizedQuery($connection, $offset = 0, $limit = 100)
    {
        $select = $connection->select()
            ->from(
                ['main_table' => $connection->getTableName('sales_order_grid')],
                [
                    'entity_id',
                    'increment_id',
                    'status',
                    'customer_email',
                    'billing_name',
                    'grand_total',
                    'shipping_amount' => 'COALESCE(main_table.shipping_amount, 0)'
                ]
            )
            ->joinLeft(
                ['order_table' => $connection->getTableName('sales_order')],
                'main_table.entity_id = order_table.entity_id',
                ['customer_note' => 'COALESCE(order_table.customer_note, "")']
            )
            ->joinLeft(
                ['billing_address' => $connection->getTableName('sales_order_address')],
                'main_table.entity_id = billing_address.parent_id AND billing_address.address_type = "billing"',
                ['governorate' => 'COALESCE(billing_address.region, "")']
            )
            ->order('main_table.entity_id DESC')
            ->limit($limit, $offset);
            
        return $select;
    }

    /**
     * Build Excel compatible order row
     *
     * @param array $order
     * @param AdapterInterface $connection
     * @return array
     */
    protected function buildExcelCompatibleOrderRow($order, $connection)
    {
        $row = [];
        
        // Add columns based on configuration
        if ($this->isColumnEnabled('include_order_id')) {
            $row[] = $this->cleanTextForExcel($order['increment_id']);
        }
        
        if ($this->isColumnEnabled('include_status')) {
            $row[] = $this->cleanTextForExcel($this->translateStatusToEnglish($order['status']));
        }
        
        if ($this->isColumnEnabled('include_billing_name')) {
            $row[] = $this->cleanTextForExcel($order['billing_name']);
        }
        
        if ($this->isColumnEnabled('include_customer_email')) {
            $row[] = $this->cleanTextForExcel($order['customer_email']);
        }
        
        if ($this->isColumnEnabled('include_governorate')) {
            $row[] = $this->cleanTextForExcel($order['governorate']);
        }
        
        if ($this->isColumnEnabled('include_grand_total')) {
            $row[] = number_format($order['grand_total'], 2);
        }
        
        if ($this->isColumnEnabled('include_shipping_amount')) {
            $row[] = number_format($order['shipping_amount'], 2);
        }
        
        if ($this->isColumnEnabled('include_customer_notes')) {
            $row[] = $this->cleanTextForExcel($order['customer_note']);
        }
        
        // Add product details if enabled
        if ($this->isProductDetailsEnabled()) {
            error_log("Product details enabled for order: " . $order['entity_id']);
            $productData = $this->getOrderProductsOptimized($order['entity_id'], $connection);
            
            if ($this->isProductColumnEnabled('include_product_skus')) {
                $row[] = $this->cleanTextForExcel($productData['skus']);
                error_log("Added SKUs: " . $productData['skus']);
            }
            
            if ($this->isProductColumnEnabled('include_product_names')) {
                $row[] = $this->cleanTextForExcel($productData['names']);
                error_log("Added Names: " . $productData['names']);
            }
            
            if ($this->isProductColumnEnabled('include_product_quantities')) {
                $row[] = $this->cleanTextForExcel($productData['quantities']);
                error_log("Added Quantities: " . $productData['quantities']);
            }
            
            if ($this->isProductColumnEnabled('include_product_prices')) {
                $row[] = $this->cleanTextForExcel($productData['prices']);
                error_log("Added Prices: " . $productData['prices']);
            }
            
            // Add additional attributes
            $additionalAttributes = $this->getAdditionalAttributes();
            foreach ($additionalAttributes as $attribute) {
                $attributeValue = $productData['attributes'][$attribute] ?? '';
                $row[] = $this->cleanTextForExcel($attributeValue);
            }
        } else {
            error_log("Product details NOT enabled");
        }
        
        return $row;
    }

    /**
     * Write Excel compatible headers
     *
     * @param \Magento\Framework\Filesystem\File\WriteInterface $stream
     * @return void
     */
    protected function writeExcelCompatibleHeaders($stream)
    {
        $headers = [];
        
        // Add headers based on configuration
        if ($this->isColumnEnabled('include_order_id')) {
            $headers[] = 'Order Number';
        }
        
        if ($this->isColumnEnabled('include_status')) {
            $headers[] = 'Status';
        }
        
        if ($this->isColumnEnabled('include_billing_name')) {
            $headers[] = 'Customer Name';
        }
        
        if ($this->isColumnEnabled('include_customer_email')) {
            $headers[] = 'Email';
        }
        
        if ($this->isColumnEnabled('include_governorate')) {
            $headers[] = 'Governorate';
        }
        
        if ($this->isColumnEnabled('include_grand_total')) {
            $headers[] = 'Total';
        }
        
        if ($this->isColumnEnabled('include_shipping_amount')) {
            $headers[] = 'Shipping';
        }
        
        if ($this->isColumnEnabled('include_customer_notes')) {
            $headers[] = 'Customer Note';
        }
        
        // Add product headers if enabled
        if ($this->isProductDetailsEnabled()) {
            error_log("Adding product headers");
            
            if ($this->isProductColumnEnabled('include_product_skus')) {
                $headers[] = 'Product SKUs';
            }
            
            if ($this->isProductColumnEnabled('include_product_names')) {
                $headers[] = 'Product Names';
            }
            
            if ($this->isProductColumnEnabled('include_product_quantities')) {
                $headers[] = 'Quantities';
            }
            
            if ($this->isProductColumnEnabled('include_product_prices')) {
                $headers[] = 'Prices';
            }
            
            // Add additional attribute headers
            $additionalAttributes = $this->getAdditionalAttributes();
            foreach ($additionalAttributes as $attribute) {
                $headers[] = ucfirst(str_replace('_', ' ', $attribute));
            }
        }
        
        error_log("Final headers: " . implode(', ', $headers));
        $this->writeExcelCompatibleRow($stream, $headers);
    }

    /**
     * Write Excel compatible row
     *
     * @param \Magento\Framework\Filesystem\File\WriteInterface $stream
     * @param array $row
     * @return void
     */
    protected function writeExcelCompatibleRow($stream, $row)
    {
        // Properly encode each field for Excel
        $csvRow = [];
        foreach ($row as $field) {
            // Wrap fields containing commas, quotes, or newlines in quotes
            if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                $csvRow[] = '"' . $field . '"';
            } else {
                $csvRow[] = $field;
            }
        }
        
        $stream->write(implode(',', $csvRow) . "\r\n");
    }

    /**
     * Get order products with optimized query
     *
     * @param int $orderId
     * @param AdapterInterface $connection
     * @return array
     */
    protected function getOrderProductsOptimized($orderId, $connection)
    {
        // Debug: Add logging to check if function is called
        error_log("Getting products for order ID: " . $orderId);
        
        $select = $connection->select()
            ->from(
                ['oi' => $connection->getTableName('sales_order_item')],
                ['sku', 'name', 'qty_ordered', 'price', 'product_id']
            )
            ->where('oi.order_id = ?', $orderId)
            ->where('oi.parent_item_id IS NULL'); // This excludes child items of configurable products
            
        $items = $connection->fetchAll($select);
        
        // Debug: Log the number of items found
        error_log("Found " . count($items) . " items for order " . $orderId);
        
        $skus = [];
        $names = [];
        $quantities = [];
        $prices = [];
        $attributes = [];
        
        $additionalAttributes = $this->getAdditionalAttributes();
        
        foreach ($items as $item) {
            $skus[] = $item['sku'];
            $names[] = $item['name'];
            $quantities[] = (int)$item['qty_ordered'];
            $prices[] = number_format($item['price'], 2);
            
            if (!empty($additionalAttributes) && $item['product_id']) {
                $productAttributes = $this->getProductAttributesOptimized(
                    $item['product_id'], 
                    $additionalAttributes, 
                    $connection
                );
                
                foreach ($additionalAttributes as $attribute) {
                    if (!isset($attributes[$attribute])) {
                        $attributes[$attribute] = [];
                    }
                    if (isset($productAttributes[$attribute])) {
                        $attributes[$attribute][] = $productAttributes[$attribute];
                    }
                }
            }
        }
        
        foreach ($attributes as $key => $values) {
            $attributes[$key] = implode(', ', array_unique($values));
        }
        
        $result = [
            'skus' => implode(', ', $skus),
            'names' => implode(', ', $names),
            'quantities' => implode(', ', $quantities),
            'prices' => implode(', ', $prices),
            'attributes' => $attributes
        ];
        
        // Debug: Log the result
        error_log("Product data result: " . json_encode($result));
        
        return $result;
    }
    
    /**
     * Get product attributes optimized
     *
     * @param int $productId
     * @param array $attributes
     * @param AdapterInterface $connection
     * @return array
     */
    protected function getProductAttributesOptimized($productId, $attributes, $connection)
    {
        $result = [];
        
        if (empty($attributes)) {
            return $result;
        }
        
        $select = $connection->select()
            ->from(
                ['e' => $connection->getTableName('catalog_product_entity')],
                []
            );
            
        foreach ($attributes as $attribute) {
            $select->joinLeft(
                [$attribute => $connection->getTableName('catalog_product_entity_varchar')],
                "e.entity_id = {$attribute}.entity_id AND {$attribute}.attribute_id = (SELECT attribute_id FROM {$connection->getTableName('eav_attribute')} WHERE attribute_code = '{$attribute}' AND entity_type_id = 4)",
                [$attribute => "{$attribute}.value"]
            );
        }
        
        $select->where('e.entity_id = ?', $productId);
        
        $productData = $connection->fetchRow($select);
        
        if ($productData) {
            foreach ($attributes as $attribute) {
                $result[$attribute] = $productData[$attribute] ?? '';
            }
        }
        
        return $result;
    }

    /**
     * Clean text for Excel compatibility
     *
     * @param string $text
     * @return string
     */
    protected function cleanTextForExcel($text)
    {
        if (empty($text)) {
            return '';
        }
        
        // Ensure proper UTF-8 encoding
        $text = trim($text);
        
        // Convert to UTF-8 if not already
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        
        // Remove control characters that might cause issues
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Escape quotes for CSV
        $text = str_replace('"', '""', $text);
        
        return $text;
    }

    /**
     * Translate order status to English
     *
     * @param string $status
     * @return string
     */
    protected function translateStatusToEnglish($status)
    {
        // Keep status in English for better compatibility
        $statusMap = [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'complete' => 'Complete',
            'canceled' => 'Canceled',
            'closed' => 'Closed',
            'refunded' => 'Refunded',
            'holded' => 'On Hold'
        ];
        
        return $statusMap[$status] ?? ucfirst($status);
    }

    /**
     * Create Excel compatible CSV file
     *
     * @return string
     */
    protected function createExcelCompatibleCsvFile()
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $fileName = 'export/enhanced_orders_' . date('Y-m-d_H-i-s') . '.csv';
        
        $directory->create('export');
        
        return $fileName;
    }

    /**
     * Get additional attributes from configuration
     *
     * @return array
     */
    protected function getAdditionalAttributes()
    {
        $attributes = $this->scopeConfig->getValue(
            'magoarab_orderexport/product_details/additional_attributes',
            ScopeInterface::SCOPE_STORE
        );
        
        return $attributes ? explode(',', $attributes) : [];
    }
}