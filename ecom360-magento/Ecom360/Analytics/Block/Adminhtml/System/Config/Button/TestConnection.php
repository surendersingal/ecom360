<?php
declare(strict_types=1);

namespace Ecom360\Analytics\Block\Adminhtml\System\Config\Button;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Test Connection" button rendered inside Stores > Configuration.
 */
class TestConnection extends Field
{
    protected $_template = 'Ecom360_Analytics::system/config/button/test_connection.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('ecom360/system/testConnection');
    }

    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        )->setData([
            'id'    => 'ecom360_test_connection',
            'label' => __('Test Connection'),
        ]);

        return $button->toHtml();
    }
}
