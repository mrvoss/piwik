<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik\DataTable\Filter;

use Piwik\DataTable\Filter;
use Piwik\DataTable\Row;
use Piwik\DataTable;
use Piwik\Metrics;

/**
 * @package Piwik
 * @subpackage DataTable
 */
class AddColumnsProcessedMetrics extends Filter
{
    protected $invalidDivision = 0;
    protected $roundPrecision = 2;
    protected $deleteRowsWithNoVisit = true;

    /**
     * @param DataTable $table
     * @param bool $deleteRowsWithNoVisit Automatically set to true when filter_add_columns_when_show_all_columns is found in the API request
     * @return AddColumnsProcessedMetrics
     */
    public function __construct($table, $deleteRowsWithNoVisit = true)
    {
        $this->deleteRowsWithNoVisit = $deleteRowsWithNoVisit;
        parent::__construct($table);
    }

    /**
     * Filters the given data table
     *
     * @param DataTable $table
     */
    public function filter($table)
    {
        $rowsIdToDelete = array();
        foreach ($table->getRows() as $key => $row) {
            $nbVisits = $this->getColumn($row, Metrics::INDEX_NB_VISITS);
            $nbActions = $this->getColumn($row, Metrics::INDEX_NB_ACTIONS);
            if ($nbVisits == 0
                && $nbActions == 0
                && $this->deleteRowsWithNoVisit
            ) {
                // case of keyword/website/campaign with a conversion for this day,
                // but no visit, we don't show it
                $rowsIdToDelete[] = $key;
                continue;
            }

            $nbVisitsConverted = (int)$this->getColumn($row, Metrics::INDEX_NB_VISITS_CONVERTED);
            if ($nbVisitsConverted > 0) {
                $conversionRate = round(100 * $nbVisitsConverted / $nbVisits, $this->roundPrecision);
                try {
                    $row->addColumn('conversion_rate', $conversionRate . "%");
                } catch (\Exception $e) {
                    // conversion_rate can be defined upstream apparently? FIXME
                }
            }

            if ($nbVisits == 0) {
                $actionsPerVisit = $averageTimeOnSite = $bounceRate = $this->invalidDivision;
            } else {
                // nb_actions / nb_visits => Actions/visit
                // sum_visit_length / nb_visits => Avg. Time on Site
                // bounce_count / nb_visits => Bounce Rate
                $actionsPerVisit = round($nbActions / $nbVisits, $this->roundPrecision);
                $visitLength = $this->getColumn($row, Metrics::INDEX_SUM_VISIT_LENGTH);
                $averageTimeOnSite = round($visitLength / $nbVisits, $rounding = 0);
                $bounceRate = round(100 * $this->getColumn($row, Metrics::INDEX_BOUNCE_COUNT) / $nbVisits, $this->roundPrecision);
            }
            try {
                $row->addColumn('nb_actions_per_visit', $actionsPerVisit);
                $row->addColumn('avg_time_on_site', $averageTimeOnSite);
                // It could be useful for API users to have raw sum length value.
                //$row->addMetadata('sum_visit_length', $visitLength);
            } catch (\Exception $e) {
            }

            try {
                $row->addColumn('bounce_rate', $bounceRate . "%");
            } catch (\Exception $e) {
            }

            $this->filterSubTable($row);
        }
        $table->deleteRows($rowsIdToDelete);
    }

    /**
     * Returns column from a given row.
     * Will work with 2 types of datatable
     * - raw datatables coming from the archive DB, which columns are int indexed
     * - datatables processed resulting of API calls, which columns have human readable english names
     *
     * @param Row|array $row
     * @param int $columnIdRaw see consts in Archive::
     * @param bool|array $mappingIdToName
     * @return mixed  Value of column, false if not found
     */
    protected function getColumn($row, $columnIdRaw, $mappingIdToName = false)
    {
        if (empty($mappingIdToName)) {
            $mappingIdToName = Metrics::$mappingFromIdToName;
        }
        $columnIdReadable = $mappingIdToName[$columnIdRaw];
        if ($row instanceof Row) {
            $raw = $row->getColumn($columnIdRaw);
            if ($raw !== false) {
                return $raw;
            }
            return $row->getColumn($columnIdReadable);
        }
        if (isset($row[$columnIdRaw])) {
            return $row[$columnIdRaw];
        }
        if (isset($row[$columnIdReadable])) {
            return $row[$columnIdReadable];
        }
        return false;
    }

}
