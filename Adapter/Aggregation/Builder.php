<?php

namespace Algolia\AlgoliaSearchElastic\Adapter\Aggregation;

use Algolia\AlgoliaSearch\Model\Indexer\Product;
use Algolia\AlgoliaSearchElastic\Helper\ElasticAdapterHelper;
use Magento\Catalog\Model\ProductFactory;
use Magento\Elasticsearch\SearchAdapter\Aggregation\Builder as ElasticSearchBuilder;
use Magento\Elasticsearch\SearchAdapter\Aggregation\Builder\BucketBuilderInterface;
use Magento\Elasticsearch\SearchAdapter\Aggregation\DataProviderFactory;
use Magento\Elasticsearch\SearchAdapter\QueryContainer;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\RequestInterface;

class Builder extends ElasticSearchBuilder
{
    /**
     * @var DataProviderFactory
     */
    private $dataProviderFactory;

    /**
     * @var QueryContainer
     */
    private $query = null;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var \Magento\Catalog\Model\Product
     */
    private $product;

    /**
     * @var array
     */
    private $facets = [];

    /**
     * @param array $dataProviderContainer
     * @param array $aggregationContainer
     * @param DataProviderFactory|null $dataProviderFactory
     * @param ProductFactory $productFactory
     */
    public function __construct(
        array $dataProviderContainer,
        array $aggregationContainer,
        DataProviderFactory $dataProviderFactory = null,
        ProductFactory $productFactory
    ) {
        $this->dataProviderContainer = array_map(
            function (DataProviderInterface $dataProvider) {
                return $dataProvider;
            },
            $dataProviderContainer
        );
        $this->aggregationContainer = array_map(
            function (BucketBuilderInterface $bucketBuilder) {
                return $bucketBuilder;
            },
            $aggregationContainer
        );
        $this->dataProviderFactory = $dataProviderFactory
            ?: ObjectManager::getInstance()->get(DataProviderFactory::class);

        $this->productFactory = $productFactory;
    }

    public function build(RequestInterface $request, array $queryResult)
    {
        $aggregations = [];
        $buckets = $request->getAggregation();

        $facets = $this->getFacets();

        $dataProvider = $this->dataProviderFactory->create(
            $this->dataProviderContainer[$request->getIndex()],
            $this->query
        );

        foreach ($buckets as $bucket) {
            if (count($facets) && isset($facets[$bucket->getField()])) {
                $aggregations[$bucket->getName()] =
                    $this->formatAggregation($bucket->getField(), $facets[$bucket->getField()]);
            } else {
                $bucketAggregationBuilder = $this->aggregationContainer[$bucket->getType()];
                $aggregations[$bucket->getName()] = $bucketAggregationBuilder->build(
                    $bucket,
                    $request->getDimensions(),
                    $queryResult,
                    $dataProvider
                );
            }
        }

        $this->query = null;

        return $aggregations;
    }


    private function formatAggregation($attribute, $facetData)
    {
        $aggregation = [];

        foreach ($facetData as $value => $count) {
            $optionId = $this->getOptionIdByLabel($attribute, $value);
            $aggregation[$optionId] = [
                'value' => (string) $optionId,
                'count' => (string) $count,
            ];
        }

        return $aggregation;
    }

    private function getOptionIdByLabel($attributeCode, $optionLabel)
    {
        $product = $this->getProduct();
        $isAttributeExist = $product->getResource()->getAttribute($attributeCode);
        $optionId = '';
        if ($isAttributeExist && $isAttributeExist->usesSource()) {
            $optionId = $isAttributeExist->getSource()->getOptionId($optionLabel);
        }

        return $optionId;
    }

    /**
     * @return \Magento\Catalog\Model\Product
     */
    private function getProduct()
    {
        if (!$this->product) {
            $this->product = $this->productFactory->create();
        }

        return $this->product;
    }

    /**
     * Sets the QueryContainer instance to the internal property in order to use it in build process
     *
     * @param QueryContainer $query
     * @return \Magento\Elasticsearch\SearchAdapter\Aggregation\Builder
     */
    public function setQuery(QueryContainer $query)
    {
        $this->query = $query;

        return $this;
    }

    public function setFacets($facets)
    {
        $this->facets = $facets;
    }

    private function getFacets()
    {
        return $this->facets;
    }

}
