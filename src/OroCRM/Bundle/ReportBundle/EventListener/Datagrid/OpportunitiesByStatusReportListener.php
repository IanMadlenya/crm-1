<?php

namespace OroCRM\Bundle\ReportBundle\EventListener\Datagrid;

use Oro\Bundle\DataGridBundle\Datasource\Orm\OrmDatasource;
use Oro\Bundle\DataGridBundle\Event\BuildAfter;
use Oro\Bundle\DataGridBundle\Event\BuildBefore;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\FilterBundle\Filter\DateFilterUtility;
use Oro\Bundle\FilterBundle\Form\Type\Filter\AbstractDateFilterType;
use Oro\Bundle\FilterBundle\Utils\DateFilterModifier;

use OroCRM\Bundle\SalesBundle\Entity\Opportunity;

/**
 * Apply query modifications to the Opportunity By Status Report
 * Add enum status class name to the FROM clause
 * Apply the defined date and datetime filters to JOIN instead of WHERE
 */
class OpportunitiesByStatusReportListener
{
    /**
     * @var array Map of date filters and comparison operators
     */
    public static $comparatorsMap = [
        AbstractDateFilterType::TYPE_LESS_THAN => '<=',
        AbstractDateFilterType::TYPE_MORE_THAN => '>=',
        AbstractDateFilterType::TYPE_EQUAL => '=',
        AbstractDateFilterType::TYPE_NOT_EQUAL => '<>',
        AbstractDateFilterType::TYPE_BETWEEN => ['>=', 'AND', '<='],
        AbstractDateFilterType::TYPE_NOT_BETWEEN => ['<', 'OR', '>'],
    ];

    /** @var DateFilterModifier */
    protected $dateFilterModifier;

    /** @var DateFilterUtility */
    protected $dateFilterUtility;

    /**
     * OpportunitiesByStatusReportListener constructor.
     *
     * @param DateFilterModifier $dateFilterModifier
     * @param DateFilterUtility $dateFilterUtility
     */
    public function __construct(
        DateFilterModifier $dateFilterModifier,
        DateFilterUtility $dateFilterUtility
    ) {
        $this->dateFilterModifier = $dateFilterModifier;
        $this->dateFilterUtility = $dateFilterUtility;
    }

    /**
     * @param BuildBefore $event
     */
    public function onBuildBefore(BuildBefore $event)
    {
        $className = ExtendHelper::buildEnumValueClassName(Opportunity::INTERNAL_STATUS_CODE);
        $config = $event->getConfig();
        $from[] = [
            'table' => $className,
            'alias' => 'status'
        ];
        $config->offsetSetByPath('[source][query][from]', $from);
    }

    /**
     * Move the date filters into join clause to avoid filtering statuses from the report
     *
     * @param BuildAfter $event
     */
    public function onBuildAfter(BuildAfter $event)
    {
        $dataGrid = $event->getDatagrid();
        $dataSource = $dataGrid->getDatasource();
        if (!$dataSource instanceof OrmDatasource) {
            return;
        }

        $joinConditions = [];
        $filters = $dataGrid->getParameters()->get('_filter');
        if (!$filters) {
            return;
        }

        $filtersConfig = $dataGrid->getConfig()->offsetGetByPath('[filters][columns]');

        // create a map of join filter conditions
        foreach ($filtersConfig as $key => $config) {
            // get date and datetime filters only
            if (in_array($config['type'], ['date', 'datetime'])
                && array_key_exists($key, $filters)
                && strpos($config['data_name'], '.') !== false
            ) {
                list($alias, $field) = explode('.', $config['data_name']);
                // build a join clause
                $dateCondition = $this->buildDateCondition($filters[$key], $config['data_name'], $config['type']);
                if ($dateCondition) {
                    $joinConditions[$alias][$field][] = $dateCondition;
                }
                // remove filters so it does not appear in the where clause
                unset($filters[$key]);
            }
        }

        // update filter params (without removed ones)
        $dataGrid->getParameters()->set('_filter', $filters);

        // Prepare new join
        $queryBuilder = $dataSource->getQueryBuilder();
        $joinParts = $queryBuilder->getDQLPart('join');

        $queryBuilder->resetDQLPart('join');

        // readd join parts and append filter conditions to the appropriate joins
        foreach ($joinParts as $joins) {
            foreach ($joins as $join) {
                /** @var \Doctrine\ORM\Query\Expr\Join $join */
                $alias = $join->getAlias();
                $fieldCondition = '';
                // check if there is a column with a join filter on this alias
                if (array_key_exists($alias, $joinConditions)) {
                    foreach ($joinConditions[$alias] as $fieldConditions) {
                        $fieldCondition .= implode($fieldConditions);
                    }
                }
                $queryBuilder->leftJoin(
                    $join->getJoin(),
                    $alias,
                    $join->getConditionType(),
                    $join->getCondition() . $fieldCondition,
                    $join->getIndexBy()
                );
            }
        }
    }

    /**
     * Generates SQL date comparison string depending on filter $options
     * Returns false if date filter options are invalid
     *
     * @param array $options Filter options
     * @param string $fieldName
     * @param string $filterType date filter type ('date' or 'datetime')
     *
     * @return string|bool
     */
    protected function buildDateCondition(array $options, $fieldName, $filterType)
    {
        // apply variables an normalize
        $data = $this->dateFilterModifier->modify($options);
        $data = $this->dateFilterUtility->parseData($fieldName, $data, $filterType);

        if (!$data || (empty($data['date_start']) && empty($data['date_end']))) {
            return false;
        }

        $type = $data['type'];

        if (!array_key_exists($type, static::$comparatorsMap)) {
            return false;
        }

        $comparator = static::$comparatorsMap[$type];

        // date range comparison
        if (is_array($comparator)) {
            return sprintf(
                ' AND (%s %s %s)',
                $this->formatComparison($fieldName, $comparator[0], $data['date_start']),
                $comparator[1],
                $this->formatComparison($fieldName, $comparator[2], $data['date_end'])
            );
        }

        $value = !empty($data['date_start']) ? $data['date_start'] : $data['date_end'];
        // simple date comparison
        return sprintf(' AND (%s)', $this->formatComparison($fieldName, $comparator, $value));
    }

    /**
     * Generates a comparison string
     *
     * @param string $fieldName
     * @param string $operator
     * @param string $value
     *
     * @return string
     */
    protected function formatComparison($fieldName, $operator, $value)
    {
        return sprintf('%s %s \'%s\'', $fieldName, $operator, $value);
    }
}
