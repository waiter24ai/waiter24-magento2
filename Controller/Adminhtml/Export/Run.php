<?php

namespace Waiter24\Export\Controller\Adminhtml\Export;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Waiter24\Export\Model\MenuExporter;

/**
 * Handles the admin "Export Now" button (AJAX). Runs the export for the
 * currently selected store scope and returns a JSON status.
 */
class Run extends Action
{
    public const ADMIN_RESOURCE = 'Waiter24_Export::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly MenuExporter $exporter,
    ) {
        parent::__construct($context);
    }

    public function execute(): JsonResult
    {
        $result = $this->resultJsonFactory->create();
        $storeId = $this->getRequest()->getParam('store');
        $storeId = $storeId !== null ? (int) $storeId : null;

        try {
            $response = $this->exporter->run($storeId);
            $stats    = $response['stats'] ?? [];

            return $result->setData([
                'success' => true,
                'message' => __(
                    'Export complete: %1 created, %2 updated.',
                    $stats['created'] ?? 0,
                    $stats['updated'] ?? 0,
                ),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
