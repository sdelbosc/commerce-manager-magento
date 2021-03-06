<?php

/**
 * Acquia/CommerceManager/Model/ProductSyncManagement.php
 *
 * Acquia Commerce Manager Product Syncronization Management
 *
 * All rights reserved. No unauthorized distribution or reproduction.
 */

namespace Acquia\CommerceManager\Model;

use Acquia\CommerceManager\Api\ProductSyncManagementInterface;
use Acquia\CommerceManager\Helper\Data as ClientHelper;
use Acquia\CommerceManager\Helper\Acm as AcmHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

/**
 * ProductSyncManagement
 *
 * Acquia Commerce Manager Product Syncronization Management
 */
class ProductSyncManagement implements ProductSyncManagementInterface
{
    /**
     * Connector Product Update Endpoint
     * @const ENDPOINT_PRODUCT_UPDATE
     */
    const ENDPOINT_PRODUCT_UPDATE = 'ingest/product';

    /**
     * @var AcmHelper $acmHelper
     */
    private $acmHelper;

    /**
     * Acquia Commerce Manager Client Helper
     * @var ClientHelper $clientHelper
     */
    private $clientHelper;

    /**
     * @var ProductRepositoryInterface $productRepository
     */
    private $productRepository;

    /**
     * @var SearchCriteriaBuilder $productRepository
     */
    private $searchCriteriaBuilder;

    /**
     * Catalog rule model.
     * @var \Magento\CatalogRule\Model\Rule $catalogRule
     */
    protected $catalogRule;

    protected $logger;

    /**
     * ProductSyncManagement constructor.
     * @param AcmHelper $acmHelper
     * @param ClientHelper $clientHelper
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        AcmHelper $acmHelper,
        ClientHelper $clientHelper,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->acmHelper = $acmHelper;
        $this->clientHelper = $clientHelper;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    /**
     * syncProducts
     *
     * {@inheritDoc}
     *
     * @param int $page_count Current Page
     * @param int $page_size Products Per Page
     * @param string $skus Comma Separated SKUs
     * @param string $category_id comma separated category IDs
     *
     * @return bool $success
     */
    public function syncProducts($page_count, $page_size = 50, $skus = '', $category_id = '')
    {
        // Set collection filters.
        if (!empty($skus))
        {
            $this->searchCriteriaBuilder->addFilter('sku', explode(',', $skus), 'in');
        }
        else if ($category_id)
        {
            $this->searchCriteriaBuilder->addFilter('category_id', explode(',', $category_id), 'in');
        }
        else
        {
            // Filter for active products.
            // We will skip this check if we are specifically asked to sync
            // some SKUs.
            $this->searchCriteriaBuilder->addFilter('status', Status::STATUS_ENABLED);
        }

        /** @var \Magento\Framework\Api\SearchCriteriaInterface $search_criteria */
        $search_criteria = $this->searchCriteriaBuilder->create();

        $search_criteria->setCurrentPage($page_count);
        $search_criteria->setPageSize($page_size);

        /** @var \Magento\Catalog\Api\Data\ProductInterface[] $products */
        $products = $this->productRepository->getList($search_criteria)->getItems();

        // Format JSON Output
        $output = [];

        foreach ($products as $product) {
            $record = $this->acmHelper->getProductDataForAPI($product);
            $storeId = $record['store_id'];
            $output[$storeId][] = $record;
            $this->logger->info('Product sync maker. (store '.$storeId.')'.$product->getSku());
        }


        // We need to have separate requests per store so we can assign them
        // correctly in middleware.
        foreach ($output as $storeId => $arrayOfProducts) {
            $this->logger->info('Product sync sender. Sending store '.$storeId.'');
            // Send Connector request.
            $doReq = function ($client, $opt) use ($arrayOfProducts) {
                $opt['json'] = $arrayOfProducts;
                return $client->post(self::ENDPOINT_PRODUCT_UPDATE, $opt);
            };

            $this->clientHelper->tryRequest($doReq, 'syncProducts', $storeId);
        }

        return (true);
    }
}
