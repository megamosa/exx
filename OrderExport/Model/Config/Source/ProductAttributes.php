<?php
/**
 * MagoArab Order Export Product Attributes Source
 * 
 * @category  MagoArab
 * @package   MagoArab_OrderExport
 * @author    MagoArab Team
 * @copyright Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderExport\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class ProductAttributes implements ArrayInterface
{
    /**
     * @var CollectionFactory
     */
    protected $attributeCollectionFactory;

    /**
     * Useful product attributes for export
     * @var array
     */
    protected $usefulAttributes = [
        'color',
        'size',
        'brand',
        'manufacturer',
        'weight',
        'material',
        'country_of_manufacture',
        'description',
        'short_description',
        'meta_title',
        'meta_description',
        'special_price',
        'cost',
        'msrp',
        'tax_class_id',
        'category_ids'
    ];

    /**
     * @param CollectionFactory $attributeCollectionFactory
     */
    public function __construct(
        CollectionFactory $attributeCollectionFactory
    ) {
        $this->attributeCollectionFactory = $attributeCollectionFactory;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray()
    {
        $options = [];
        
        // Get all product attributes
        $collection = $this->attributeCollectionFactory->create()
            ->addVisibleFilter()
            ->addFieldToFilter('is_user_defined', 1);

        // Add system attributes that are useful
        $systemCollection = $this->attributeCollectionFactory->create()
            ->addFieldToFilter('attribute_code', ['in' => $this->usefulAttributes]);
            
        // Combine collections
        $allAttributes = [];
        
        // Add user-defined attributes
        foreach ($collection as $attribute) {
            $code = $attribute->getAttributeCode();
            $label = $attribute->getFrontendLabel() ?: $attribute->getAttributeCode();
            
            // Skip attributes that don't have meaningful values
            if ($this->isUsefulAttribute($attribute)) {
                $allAttributes[$code] = $label;
            }
        }
        
        // Add useful system attributes
        foreach ($systemCollection as $attribute) {
            $code = $attribute->getAttributeCode();
            $label = $attribute->getFrontendLabel() ?: $this->getSystemAttributeLabel($code);
            $allAttributes[$code] = $label;
        }
        
        // Sort alphabetically
        asort($allAttributes);
        
        // Convert to option array format
        foreach ($allAttributes as $code => $label) {
            $options[] = [
                'value' => $code,
                'label' => $label
            ];
        }

        return $options;
    }
    
    /**
     * Check if attribute is useful for export
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @return bool
     */
    protected function isUsefulAttribute($attribute)
    {
        $code = $attribute->getAttributeCode();
        
        // Skip attributes that are typically not useful
        $skipAttributes = [
            'gallery',
            'image',
            'small_image',
            'thumbnail',
            'media_gallery',
            'tier_price',
            'recurring_profile',
            'minimal_price',
            'msrp_display_actual_price_type',
            'page_layout',
            'options_container',
            'custom_layout_update',
            'gift_message_available'
        ];
        
        if (in_array($code, $skipAttributes)) {
            return false;
        }
        
        // Skip attributes without frontend labels
        if (!$attribute->getFrontendLabel()) {
            return false;
        }
        
        // Only include attributes that can have values
        $inputTypes = ['text', 'textarea', 'select', 'multiselect', 'date', 'boolean', 'price'];
        if (!in_array($attribute->getFrontendInput(), $inputTypes)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get system attribute label
     *
     * @param string $code
     * @return string
     */
    protected function getSystemAttributeLabel($code)
    {
        $labels = [
            'color' => 'Color',
            'size' => 'Size',
            'brand' => 'Brand',
            'manufacturer' => 'Manufacturer',
            'weight' => 'Weight',
            'material' => 'Material',
            'country_of_manufacture' => 'Country of Manufacture',
            'description' => 'Description',
            'short_description' => 'Short Description',
            'meta_title' => 'Meta Title',
            'meta_description' => 'Meta Description',
            'special_price' => 'Special Price',
            'cost' => 'Cost',
            'msrp' => 'MSRP',
            'tax_class_id' => 'Tax Class',
            'category_ids' => 'Categories'
        ];
        
        return $labels[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }
}