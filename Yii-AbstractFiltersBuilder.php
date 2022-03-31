<?php
/**
 * Class AbstractFiltersBuilder
 * @package apimodule\filters
 */
declare(strict_types=1);

namespace apimodule\filters;

use app\helpers\CountryHelper;
use yii\helpers\ArrayHelper;
use RzCommon\i18nDb\Language;
use apimodule\extend\CachedTrait;

use apimodule\filters\storage\FiltersSettings;

use apimodule\filters\storage\OrderSettings;
use apimodule\filters\modules\catalog\traits\{
    PriceTrait,
    SellStatusTrait,
    StateTrait,
    SellerTrait,
    SeriesTrait,
    DynamicTrait,
    ProducerTrait,
    CategoryTrait,
    PromotionGoodsTrait,
    LoyaltyProgramTrait
};

/** Class AbstractFiltersBuilder */
abstract class AbstractFiltersBuilder implements IFiltersBuilder
{
    use PriceTrait;
    use StateTrait;
    use SellerTrait;
    use SeriesTrait;
    use DynamicTrait;
    use ProducerTrait;
    use CategoryTrait;
    use PromotionGoodsTrait;
    use LoyaltyProgramTrait;
    use SellStatusTrait;

    /** @var int */
    protected $categoryId;

    /** @var IFiltersSearcher|CachedTrait */
    protected $searcher;

    /** @var array */
    protected $condition;

    /** @var bool */
    protected $hasCondition;

    /** @var array */
    protected $filters;

    /** @var array */
    protected $chosen;

    /**
     * AbstractFiltersBuilder constructor
     * @param int $categoryId
     * @param IFiltersSearcher $searcher
     * @param array $condition
     * @param bool $hasCondition
     */
    public function __construct(
        int $categoryId,
        IFiltersSearcher $searcher,
        array $condition,
        bool $hasCondition
    ) {
        $this->categoryId = $categoryId;
        $this->searcher = $searcher;
        $this->condition = $condition;
        $this->hasCondition = $hasCondition;

        $this->filters = [];
        $this->chosen = [];
    }

    /**
     * @param int $categoryId
     * @return self
     */
    public function setCategoryId(int $categoryId): self
    {
        $this->categoryId = $categoryId;
        return $this;
    }

    /**
     * @param array $condition
     * @return self
     */
    public function setCondition(array $condition): self
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * @param bool $hasCondition
     * @return self
     */
    public function setHasCondition(bool $hasCondition): self
    {
        $this->hasCondition = $hasCondition;
        return $this;
    }

    /** @return array */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Возвращает выбранные фильтра
     * @inheridoc
     * @return array
     */
    public function getChosen(): array
    {
        return $this->chosen;
    }

    /**
     * Построение фильтров
     * @return self
     */
    public function buildFilters(): self
    {
        $this->buildDynamics();
        $this->buildCategory();
        $this->buildPrice();
        $this->buildProducer();
        $this->buildSeries();
        /**
         * Временный костыль, скорее всего со временем надо будет убрать
         */
        if (CountryHelper::getCurrentCountry() !== CountryHelper::COUNTRY_UZ) {
            $this->buildSeller();
        }
        $this->buildLoyaltyProgram();
        $this->buildState();
        $this->buildPromotionGoods();
        $this->buildSellStatus();
        $this->markFiltersOrder();

        return $this;
    }

    /**
     * Возвращает перевод на текущий язык по ключу
     * @param string $key
     * @param array $options
     * @return string
     */
    protected function getTranslation(string $key, array $options = []): string
    {
        return \Yii::t('filter', $key, $options, Language::getCurLang());
    }

    /**
     * Устанавливает значения фильтров в поисковик
     * @param array $condition
     */
    protected function setSearchFilters(array $condition): void
    {
        $this->searcher->setFilters($condition);
    }

    /**
     * Маркирует порядок вывода фильтров
     * @param array|null $filtersMarks
     * @return void
     */
    protected function markFiltersOrder(?array $filtersMarks = null): void
    {
        $filtersMarks = $filtersMarks ?? $this->getOrderedSequence();

        foreach ($this->filters as $filterKey => $filter) {
            $optionName = $filter['option_name'];
            if (\in_array($optionName, $filtersMarks)) {
                $this->filters[$filterKey]['order'] = \array_search($optionName, $filtersMarks);
            }
        }
    }

    /**
     * Сортируе значения фильтра
     * @param array $filter
     * @param bool $withAutoRanking
     * @return mixed
     */
    protected function sortFilterValues(array $filter, bool $withAutoRanking): array
    {
        if ($filter['disallow_import_filters_orders'] ?? false) {
            return $this->prepareValuesWithoutAutoRanking($filter);
        }

        $values = $filter['option_values'];

        $rankedValues = $this->sortValuesByRank($values);

        $rankedCount = $rankedValues['rank_count'];
        unset($rankedValues['rank_count'], $rankedValues['rank_active_count']);

        $shortListCount = $rankedCount <= 0 || !$withAutoRanking
            ? FiltersSettings::getShortListCount()
            : $rankedCount;

        $totalFound = 0;
        $totalFiltered = 0;

        $nonSortedShortList = [];
        foreach ($rankedValues as $key => $value) {
            if (!$value['disabled']) {
                $totalFiltered++;
            }

            if ($totalFound < FiltersSettings::getShortListCount()
                && (!$withAutoRanking || ($withAutoRanking && $totalFound < $shortListCount))
            ) {
                $nonSortedShortList[] = $value;
            }

            $totalFound++;
        }

        $rankedShortList = [];
        $nonRankedShortList = [];
        foreach ($nonSortedShortList as $shortListValue) {
            if ($shortListValue['is_rank']) {
                $rankedShortList[] = $shortListValue;
            } else {
                $nonRankedShortList[] = $shortListValue;
            }
        }

        foreach ([&$rankedShortList, &$nonRankedShortList] as &$list) {
            ArrayHelper::multisort($list, ['option_value_title'], [SORT_ASC]);
        }

        $shortList = \array_merge($rankedShortList, $nonRankedShortList);

        $filter['short_list'] = $shortList;
        $filter['total_filtered'] = $totalFiltered;
        $filter['total_found'] = $totalFound;

        $shortListIds = \array_column($shortList, 'option_value_id');
        $restList = $values;
        foreach ($values as $valueId => $value) {
            if (!isset($value['option_value_id'])
                || $value['option_value_id'] === 0
                || \in_array($valueId, $shortListIds)
            ) {
                unset($restList[$valueId]);
            }
        }

        ArrayHelper::multisort($restList, ['disabled', 'option_value_title'], [SORT_ASC, SORT_ASC]);

        $filter['option_values'] = \array_merge($shortList, $restList);

        return $filter;
    }

    /**
     * Сортирует значения фильтра по рангу
     * @param array $values
     * @param bool $withCounts
     * @return array
     */
    private function sortValuesByRank(array $values, bool $withCounts = true): array
    {
        return FiltersHelper::sortValuesByRank($values, $withCounts);
    }

    /**
     * Сортировка значений для фильтра без ранжирования по AutoRankings
     * @param array $filter
     * @return array
     */
    private function prepareValuesWithoutAutoRanking(array $filter): array
    {
        $totalFiltered = 0;
        $rankedCount = 0;
        $activeRankedCount = 0;

        $values = $filter['option_values'];
        foreach ($values as $value) {
            if ($value['is_rank']) {
                $rankedCount++;
                if (!($value['disabled'])) {
                    $activeRankedCount++;
                }
            }

            if (!$value['disabled']) {
                $totalFiltered++;
            }
        }

        $filter['total_found'] = \count($values);
        $filter['total_filtered'] = $totalFiltered;
        $filter['short_list'] = \array_slice($values, 0, FiltersSettings::getShortListCount());;
        $filter['option_values'] = \array_values($values);

        return $filter;
    }

    /**
     * Извлекает индикаторы фильтров
     * @param string $mark
     * @return array
     */
    private function extractsColumn(string $mark = 'option_name'): array
    {
        $filtersMarks = [];
        foreach ($this->filters as $filter) {
            $filtersMarks[] = $filter[$mark];
        }

        return $filtersMarks;
    }

    /**
     * Формируем порядок вывода фильтров
     * @return array
     */
    private function getOrderedSequence(): array
    {
        $filtersMarks = $this->extractsColumn();
        $filteredKeys = [];

        /** Формируем заглавные индексы фильтров */
        foreach (OrderSettings::FILTERS_FIRSTS_ORDER as $key) {
            if (\in_array($key, $filtersMarks)) {
                $filteredKeys[] = $key;
                unset($filtersMarks[\array_search($key, $filtersMarks)]);
            }
        }

        /** Формируем последовательность индексов зависимых фильтров */
        foreach (OrderSettings::FILTERS_RATIONS_ORDER as $master => $slaves) {
            if (\in_array($master, $filteredKeys)) {
                $masterOrderKey = \array_search($master, $filteredKeys);
                foreach ($slaves as $slave) {
                    if (\in_array($slave, $filtersMarks)) {
                        \array_splice(
                            $filteredKeys,
                            $masterOrderKey+1,
                            0,
                            $slave
                        );
                        unset($filtersMarks[\array_search($slave, $filtersMarks)]);
                    }
                }
            }
        }

        /** Формируем индексы основных фильтров */
        foreach ($filtersMarks as $key) {
            if (!\in_array($key, OrderSettings::FILTERS_LASTS_ORDER)) {
                $filteredKeys[] = $key;
                unset($filtersMarks[\array_search($key, $filtersMarks)]);
            }
        }

        /** Формируем оставшиеся индексы фильтров */
        foreach (OrderSettings::FILTERS_LASTS_ORDER as $key) {
            if (\in_array($key, $filtersMarks)) {
                $filteredKeys[] = $key;
                unset($filtersMarks[\array_search($key, $filtersMarks)]);
            }
        }

        return $filteredKeys;
    }
}
