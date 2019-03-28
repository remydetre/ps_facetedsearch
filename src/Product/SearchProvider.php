<?php
/**
 * 2007-2019 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
namespace PrestaShop\Module\FacetedSearch\Product;

use Configuration;
use Context;
use PrestaShop\Module\FacetedSearch\Filters;
use PrestaShop\Module\FacetedSearch\URLSerializer;
use PrestaShop\PrestaShop\Core\Product\Search\Facet;
use PrestaShop\PrestaShop\Core\Product\Search\FacetCollection;
use PrestaShop\PrestaShop\Core\Product\Search\FacetsRendererInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use PrestaShop\PrestaShop\Core\Product\Search\URLFragmentSerializer;
use Ps_Facetedsearch;
use Tools;

class SearchProvider implements FacetsRendererInterface, ProductSearchProviderInterface
{
    /**
     * @var bool
     */
    private $isAjax;
    /**
     * @Ps_Facetedsearch
     */
    private $module;

    /**
     * @var Filters\Converter
     */
    private $filtersConverter;

    /**
     * @var URLSerializer
     */
    private $facetsSerializer;

    public function __construct(Ps_Facetedsearch $module, $isAjax)
    {
        $this->isAjax = $isAjax;
        $this->module = $module;
        $this->filtersConverter = new Filters\Converter();
        $this->facetsSerializer = new URLSerializer();
    }

    /**
     * @return array
     */
    private function getAvailableSortOrders()
    {
        $sortPosAsc = new SortOrder('product', 'position', 'asc');
        $sortNameAsc = new SortOrder('product', 'name', 'asc');
        $sortNameDesc = new SortOrder('product', 'name', 'desc');
        $sortPriceAsc = new SortOrder('product', 'price', 'asc');
        $sortPriceDesc = new SortOrder('product', 'price', 'desc');
        $translator = $this->module->getTranslator();

        return [
            $sortPosAsc->setLabel(
                $translator->trans('Relevance', [], 'Modules.Facetedsearch.Shop')
            ),
            $sortNameAsc->setLabel(
                $translator->trans('Name, A to Z', [], 'Shop.Theme.Catalog')
            ),
            $sortNameDesc->setLabel(
                $translator->trans('Name, Z to A', [], 'Shop.Theme.Catalog')
            ),
            $sortPriceAsc->setLabel(
                $translator->trans('Price, low to high', [], 'Shop.Theme.Catalog')
            ),
            $sortPriceDesc->setLabel(
                $translator->trans('Price, high to low', [], 'Shop.Theme.Catalog')
            ),
        ];
    }

    /**
     * @param ProductSearchContext $context
     * @param ProductSearchQuery $query
     *
     * @return ProductSearchResult
     */
    public function runQuery(
        ProductSearchContext $context,
        ProductSearchQuery $query
    ) {
        $result = new ProductSearchResult();
        // extract the filter array from the Search query
        $facetedSearchFilters = $this->filtersConverter->createFacetedSearchFiltersFromQuery($query);

        $facetedSearch = new Search();
        // init the search with the initial population associated with the current filters
        $facetedSearch->initSearch($facetedSearchFilters);

        $orderBy = $query->getSortOrder()->toLegacyOrderBy(false);
        $orderWay = $query->getSortOrder()->toLegacyOrderWay();

        $filterProductSearch = new Filters\Products($facetedSearch);

        // get the product associated with the current filter
        $productsAndCount = $filterProductSearch->getProductByFilters(
            $query->getResultsPerPage(),
            $query->getPage(),
            $orderBy,
            $orderWay,
            $facetedSearchFilters
        );

        $result
            ->setProducts($productsAndCount['products'])
            ->setTotalProductsCount($productsAndCount['count'])
            ->setAvailableSortOrders($this->getAvailableSortOrders());

        // now get the filter blocks associated with the current search
        $filterBlockSearch = new Filters\Block($facetedSearch);

        $currentContext = Context::getContext();
        $idShop = (int) $currentContext->shop->id;
        $idLang = (int) $currentContext->language->id;
        $idCurrency = (int) $currentContext->currency->id;
        $idCountry = (int) $currentContext->country->id;
        $idCategory = (int) $query->getIdCategory();

        $filterHash = md5(
            sprintf(
                '%d-%d-%d-%d-%d-%s',
                $idShop,
                $idCurrency,
                $idLang,
                $idCategory,
                $idCountry,
                serialize($facetedSearchFilters)
            )
        );

        $filterBlock = $filterBlockSearch->getFromCache($filterHash);
        if (empty($filterBlock)) {
            $filterBlock = $filterBlockSearch->getFilterBlock($productsAndCount['count'], $facetedSearchFilters);
            $filterBlockSearch->insertIntoCache($filterHash, $filterBlock);
        }

        $facets = $this->filtersConverter->getFacetsFromFilterBlocks(
            $filterBlock['filters']
        );

        $this->labelRangeFilters($facets);
        $this->addEncodedFacetsToFilters($facets);
        $this->hideZeroValues($facets);
        $this->hideUselessFacets($facets);

        $facetCollection = new FacetCollection();
        $nextMenu = $facetCollection->setFacets($facets);
        $result->setFacetCollection($nextMenu);
        $result->setEncodedFacets($this->facetsSerializer->serialize($facets));

        return $result;
    }
    /**
     * Renders an product search result.
     *
     * @param ProductSearchContext $context
     * @param ProductSearchResult  $result
     *
     * @return string the HTML of the facets
     */
    public function renderFacets(ProductSearchContext $context, ProductSearchResult $result)
    {
        list($activeFilters, $facetsVar) = $this->prepareActiveFiltersForRender($context, $result);

        return $this->module->render(
            'views/templates/front/catalog/facets.tpl',
            [
                'facets' => $facetsVar,
                'js_enabled' => $this->isAjax,
                'activeFilters' => $activeFilters,
                'sort_order' => $result->getCurrentSortOrder()->toString(),
                'clear_all_link' => $this->updateQueryString(
                    [
                        'q' => null,
                        'page' => null,
                    ],
                ),
            ]
        );
    }

    /**
     * Renders an product search result of active filters.
     *
     * @param ProductSearchContext $context
     * @param ProductSearchResult  $result
     *
     * @return string the HTML of the facets
     */
    public function renderActiveFilters(ProductSearchContext $context, ProductSearchResult $result)
    {
        list($activeFilters,) = $this->prepareActiveFiltersForRender($context, $result);
        return $this->module->render(
            'views/templates/front/catalog/active-filters.tpl',
            [
                'activeFilters' => $activeFilters,
                'clear_all_link' => $this->updateQueryString(
                    [
                        'q' => null,
                        'page' => null,
                    ],
                ),
            ]
        );
    }

    /**
     * Prepare active filters for renderer.
     *
     * @param ProductSearchContext $context
     * @param ProductSearchResult  $result
     *
     * @return array|null
     */
    private function prepareActiveFiltersForRender(ProductSearchContext $context, ProductSearchResult $result)
    {
        $facetCollection = $result->getFacetCollection();
        // not all search providers generate menus
        if (empty($facetCollection)) {
            return null;
        }

        $facetsVar = array_map(
            [$this, 'prepareFacetForTemplate'],
            $facetCollection->getFacets()
        );

        $activeFilters = [];
        foreach ($facetsVar as $facet) {
            foreach ($facet['filters'] as $filter) {
                if ($filter['active']) {
                    $activeFilters[] = $filter;
                }
            }
        }

        return [
            $activeFilters,
            $facetsVar,
        ];
    }

    /**
     * Converts a Facet to an array with all necessary
     * information for templating.
     *
     * @param Facet $facet
     *
     * @return array ready for templating
     */
    protected function prepareFacetForTemplate(Facet $facet)
    {
        $facetsArray = $facet->toArray();
        foreach ($facetsArray['filters'] as &$filter) {
            $filter['facetLabel'] = $facet->getLabel();
            if ($filter['nextEncodedFacets']) {
                $filter['nextEncodedFacetsURL'] = $this->updateQueryString([
                    'q' => $filter['nextEncodedFacets'],
                    'page' => null,
                ]);
            } else {
                $filter['nextEncodedFacetsURL'] = $this->updateQueryString([
                    'q' => null,
                ]);
            }
        }
        unset($filter);

        return $facetsArray;
    }

    /**
     * Add a label associated with the facets
     *
     * @param array $facets
     */
    private function labelRangeFilters(array $facets)
    {
        foreach ($facets as $facet) {
            if ($facet->getType() === 'weight') {
                $unit = Configuration::get('PS_WEIGHT_UNIT');
                foreach ($facet->getFilters() as $filter) {
                    $filterValue = $filter->getValue();
                    $filter->setLabel(
                        sprintf(
                            '%1$s%2$s - %3$s%4$s',
                            Tools::displayNumber($filterValue['from']),
                            $unit,
                            Tools::displayNumber($filterValue['to']),
                            $unit
                        )
                    );
                }
            } elseif ($facet->getType() === 'price') {
                foreach ($facet->getFilters() as $filter) {
                    $filterValue = $filter->getValue();
                    $filter->setLabel(
                        sprintf(
                            '%1$s - %2$s',
                            Tools::displayPrice($filterValue['from']),
                            Tools::displayPrice($filterValue['to'])
                        )
                    );
                }
            }
        }
    }

    /**
     * This method generates a URL stub for each filter inside the given facets
     * and assigns this stub to the filters.
     * The URL stub is called 'nextEncodedFacets' because it is used
     * to generate the URL of the search once a filter is activated.
     */
    private function addEncodedFacetsToFilters(array $facets)
    {
        // first get the currently active facetFilter in an array
        $activeFacetFilters = $this->facetsSerializer->getActiveFacetFiltersFromFacets($facets);
        $urlSerializer = new URLFragmentSerializer();

        foreach ($facets as $facet) {
            // If only one filter can be selected, we keep track of
            // the current active filter to disable it before generating the url stub
            // and not select two filters in a facet that can have only one active filter.
            if (!$facet->isMultipleSelectionAllowed()) {
                foreach ($facet->getFilters() as $filter) {
                    if ($filter->isActive()) {
                        // we have a currently active filter is the facet, remove it from the facetFilter array
                        $activeFacetFilters = $this->facetsSerializer->removeFilterFromFacetFilters(
                            $activeFacetFilters,
                            $filter,
                            $facet
                        );
                        break;
                    }
                }
            }

            foreach ($facet->getFilters() as $filter) {
                $facetFilters = $activeFacetFilters;

                // toggle the current filter
                if ($filter->isActive()) {
                    $facetFilters = $this->facetsSerializer->removeFilterFromFacetFilters(
                        $facetFilters,
                        $filter,
                        $facet
                    );
                } else {
                    $facetFilters = $this->facetsSerializer->addFilterToFacetFilters(
                        $facetFilters,
                        $filter,
                        $facet
                    );
                }

                // We've toggled the filter, so the call to serialize
                // returns the "URL" for the search when user has toggled
                // the filter.
                $filter->setNextEncodedFacets(
                    $urlSerializer->serialize($facetFilters)
                );
            }
        }
    }

    /**
     * Hide entries with 0 results
     *
     * @param array $facets
     */
    private function hideZeroValues(array $facets)
    {
        foreach ($facets as $facet) {
            foreach ($facet->getFilters() as $filter) {
                if ($filter->getMagnitude() === 0) {
                    $filter->setDisplayed(false);
                }
            }
        }
    }

    /**
     * Remove the facet when there's only 1 result.
     * Keep facet status when it's a slider
     *
     * @param array $facets
     */
    private function hideUselessFacets(array $facets)
    {
        foreach ($facets as $facet) {
            if ($facet->getWidgetType() === 'slider') {
                // Do not change displayed property
                continue;
            }

            $usefulFiltersCount = 0;
            foreach ($facet->getFilters() as $filter) {
                if ($filter->getMagnitude() > 0) {
                    ++$usefulFiltersCount;
                }
            }

            $facet->setDisplayed(
                $usefulFiltersCount > 1
            );
        }
    }

    /**
     * Generate a URL corresponding to the current page but
     * with the query string altered.
     *
     * If $extraParams is set to NULL, then all query params are stripped.
     *
     * Otherwise, params from $extraParams that have a null value are stripped,
     * and other params are added. Params not in $extraParams are unchanged.
     */
    private function updateQueryString(array $extraParams = null)
    {
        $uriWithoutParams = explode('?', $_SERVER['REQUEST_URI'])[0];
        $url = Tools::getCurrentUrlProtocolPrefix() . $_SERVER['HTTP_HOST'] . $uriWithoutParams;
        $params = [];
        $paramsFromUri = '';
        if (strpos($_SERVER['REQUEST_URI'], '?') !== false) {
            $paramsFromUri = explode('?', $_SERVER['REQUEST_URI'])[1];
        }
        parse_str($paramsFromUri, $params);

        if (null === $extraParams) {
            $params = [];
        } else {
            foreach ($extraParams as $key => $value) {
                if (null === $value) {
                    unset($params[$key]);
                } else {
                    $params[$key] = $value;
                }
            }

            foreach ($params as $key => $param) {
                if (null === $param || '' === $param) {
                    unset($params[$key]);
                }
            }
        }

        $queryString = str_replace('%2F', '/', http_build_query($params, '', '&'));

        return $url . ($queryString ? "?$queryString" : '');
    }
}
