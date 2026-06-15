<?php

namespace Waiter24\Export\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Export Now" button in the module's admin config and wires it to
 * the AJAX run controller.
 */
class RunButton extends Field
{
    protected $_template = 'Waiter24_Export::system/config/run_button.phtml';

    public function __construct(
        Context $context,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('waiter24_export/export/run', ['store' => $this->getRequest()->getParam('store')]);
    }

    public function getButtonHtml(): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class);
        $button->setData([
            'id'    => 'waiter24_export_run_button',
            'label' => __('Export Now'),
        ]);

        return $button->toHtml();
    }
}
