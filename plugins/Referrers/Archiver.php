<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package Referrers
 */
namespace Piwik\Plugins\Referrers;

use Exception;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataArray;
use Piwik\Metrics;

class Archiver extends \Piwik\Plugin\Archiver
{
    const SEARCH_ENGINES_RECORD_NAME = 'Referrers_keywordBySearchEngine';
    const KEYWORDS_RECORD_NAME = 'Referrers_searchEngineByKeyword';
    const CAMPAIGNS_RECORD_NAME = 'Referrers_keywordByCampaign';
    const WEBSITES_RECORD_NAME = 'Referrers_urlByWebsite';
    const REFERRER_TYPE_RECORD_NAME = 'Referrers_type';
    const METRIC_DISTINCT_SEARCH_ENGINE_RECORD_NAME = 'Referrers_distinctSearchEngines';
    const METRIC_DISTINCT_KEYWORD_RECORD_NAME = 'Referrers_distinctKeywords';
    const METRIC_DISTINCT_CAMPAIGN_RECORD_NAME = 'Referrers_distinctCampaigns';
    const METRIC_DISTINCT_WEBSITE_RECORD_NAME = 'Referrers_distinctWebsites';
    const METRIC_DISTINCT_URLS_RECORD_NAME = 'Referrers_distinctWebsitesUrls';

    protected $columnToSortByBeforeTruncation;
    protected $maximumRowsInDataTableLevelZero;
    protected $maximumRowsInSubDataTable;
    /* @var array[DataArray] $arrays */
    protected $arrays = array();
    protected $distinctUrls = array();

    function __construct($processor)
    {
        parent::__construct($processor);
        $this->columnToSortByBeforeTruncation = Metrics::INDEX_NB_VISITS;

        // Reading pre 2.0 config file settings
        $this->maximumRowsInDataTableLevelZero = @Config::getInstance()->General['datatable_archiving_maximum_rows_referers'];
        $this->maximumRowsInSubDataTable = @Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_referers'];
        if (empty($this->maximumRowsInDataTableLevelZero)) {
            $this->maximumRowsInDataTableLevelZero = Config::getInstance()->General['datatable_archiving_maximum_rows_referrers'];
            $this->maximumRowsInSubDataTable = Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_referrers'];
        }
    }

    public function archiveDay()
    {
        foreach ($this->getRecordNames() as $record) {
            $this->arrays[$record] = new DataArray();
        }
        $query = $this->getLogAggregator()->queryVisitsByDimension(array("referer_type", "referer_name", "referer_keyword", "referer_url"));
        $this->aggregateFromVisits($query);

        $query = $this->getLogAggregator()->queryConversionsByDimension(array("referer_type", "referer_name", "referer_keyword"));
        $this->aggregateFromConversions($query);

        $this->recordDayReports();
    }

    protected function getRecordNames()
    {
        return array(
            self::REFERRER_TYPE_RECORD_NAME,
            self::KEYWORDS_RECORD_NAME,
            self::SEARCH_ENGINES_RECORD_NAME,
            self::WEBSITES_RECORD_NAME,
            self::CAMPAIGNS_RECORD_NAME,
        );
    }

    protected function aggregateFromVisits($query)
    {
        while ($row = $query->fetch()) {
            $this->makeReferrerTypeNonEmpty($row);
            $this->aggregateVisit($row);
        }
    }

    protected function makeReferrerTypeNonEmpty(&$row)
    {
        if (empty($row['referer_type'])) {
            $row['referer_type'] = Common::REFERRER_TYPE_DIRECT_ENTRY;
        }
    }

    protected function aggregateVisit($row)
    {
        switch ($row['referer_type']) {
            case Common::REFERRER_TYPE_SEARCH_ENGINE:
                if (empty($row['referer_keyword'])) {
                    $row['referer_keyword'] = API::LABEL_KEYWORD_NOT_DEFINED;
                }
                $searchEnginesArray = $this->getDataArray(self::SEARCH_ENGINES_RECORD_NAME);
                $searchEnginesArray->sumMetricsVisits($row['referer_name'], $row);
                $searchEnginesArray->sumMetricsVisitsPivot($row['referer_name'], $row['referer_keyword'], $row);
                $keywordsDataArray = $this->getDataArray(self::KEYWORDS_RECORD_NAME);
                $keywordsDataArray->sumMetricsVisits($row['referer_keyword'], $row);
                $keywordsDataArray->sumMetricsVisitsPivot($row['referer_keyword'], $row['referer_name'], $row);
                break;

            case Common::REFERRER_TYPE_WEBSITE:
                $this->getDataArray(self::WEBSITES_RECORD_NAME)->sumMetricsVisits($row['referer_name'], $row);
                $this->getDataArray(self::WEBSITES_RECORD_NAME)->sumMetricsVisitsPivot($row['referer_name'], $row['referer_url'], $row);

                $urlHash = substr(md5($row['referer_url']), 0, 10);
                if (!isset($this->distinctUrls[$urlHash])) {
                    $this->distinctUrls[$urlHash] = true;
                }
                break;

            case Common::REFERRER_TYPE_CAMPAIGN:
                if (!empty($row['referer_keyword'])) {
                    $this->getDataArray(self::CAMPAIGNS_RECORD_NAME)->sumMetricsVisitsPivot($row['referer_name'], $row['referer_keyword'], $row);
                }
                $this->getDataArray(self::CAMPAIGNS_RECORD_NAME)->sumMetricsVisits($row['referer_name'], $row);
                break;

            case Common::REFERRER_TYPE_DIRECT_ENTRY:
                // direct entry are aggregated below in $this->metricsByType array
                break;

            default:
                throw new Exception("Non expected referer_type = " . $row['referer_type']);
                break;
        }
        $this->getDataArray(self::REFERRER_TYPE_RECORD_NAME)->sumMetricsVisits($row['referer_type'], $row);
    }

    /**
     * @param string $name
     * @return DataArray[]
     */
    protected function getDataArray($name)
    {
        return $this->arrays[$name];
    }

    protected function aggregateFromConversions($query)
    {
        if ($query === false) {
            return;
        }
        while ($row = $query->fetch()) {
            $this->makeReferrerTypeNonEmpty($row);

            $skipAggregateByType = $this->aggregateConversion($row);
            if (!$skipAggregateByType) {
                $this->getDataArray(self::REFERRER_TYPE_RECORD_NAME)->sumMetricsGoals($row['referer_type'], $row);
            }
        }

        foreach ($this->arrays as $dataArray) {
            /* @var DataArray $dataArray */
            $dataArray->enrichMetricsWithConversions();
        }
    }

    protected function aggregateConversion($row)
    {
        $skipAggregateByType = false;
        switch ($row['referer_type']) {
            case Common::REFERRER_TYPE_SEARCH_ENGINE:
                if (empty($row['referer_keyword'])) {
                    $row['referer_keyword'] = API::LABEL_KEYWORD_NOT_DEFINED;
                }

                $this->getDataArray(self::SEARCH_ENGINES_RECORD_NAME)->sumMetricsGoals($row['referer_name'], $row);
                $this->getDataArray(self::KEYWORDS_RECORD_NAME)->sumMetricsGoals($row['referer_keyword'], $row);
                break;

            case Common::REFERRER_TYPE_WEBSITE:
                $this->getDataArray(self::WEBSITES_RECORD_NAME)->sumMetricsGoals($row['referer_name'], $row);
                break;

            case Common::REFERRER_TYPE_CAMPAIGN:
                if (!empty($row['referer_keyword'])) {
                    $this->getDataArray(self::CAMPAIGNS_RECORD_NAME)->sumMetricsGoalsPivot($row['referer_name'], $row['referer_keyword'], $row);
                }
                $this->getDataArray(self::CAMPAIGNS_RECORD_NAME)->sumMetricsGoals($row['referer_name'], $row);
                break;

            case Common::REFERRER_TYPE_DIRECT_ENTRY:
                // Direct entry, no sub dimension
                break;

            default:
                // The referer type is user submitted for goal conversions, we ignore any malformed value
                // Continue to the next while iteration
                $skipAggregateByType = true;
                break;
        }
        return $skipAggregateByType;
    }

    /**
     * Records the daily stats (numeric or datatable blob) into the archive tables.
     */
    protected function recordDayReports()
    {
        $this->recordDayNumeric();
        $this->recordDayBlobs();
    }

    protected function recordDayNumeric()
    {
        $numericRecords = array(
            self::METRIC_DISTINCT_SEARCH_ENGINE_RECORD_NAME => count($this->getDataArray(self::SEARCH_ENGINES_RECORD_NAME)),
            self::METRIC_DISTINCT_KEYWORD_RECORD_NAME       => count($this->getDataArray(self::KEYWORDS_RECORD_NAME)),
            self::METRIC_DISTINCT_CAMPAIGN_RECORD_NAME      => count($this->getDataArray(self::CAMPAIGNS_RECORD_NAME)),
            self::METRIC_DISTINCT_WEBSITE_RECORD_NAME       => count($this->getDataArray(self::WEBSITES_RECORD_NAME)),
            self::METRIC_DISTINCT_URLS_RECORD_NAME          => count($this->distinctUrls),
        );

        $this->getProcessor()->insertNumericRecords($numericRecords);
    }

    protected function recordDayBlobs()
    {
        foreach ($this->getRecordNames() as $recordName) {
            $dataArray = $this->getDataArray($recordName);
            $table = $this->getProcessor()->getDataTableFromDataArray($dataArray);
            $blob = $table->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);
            $this->getProcessor()->insertBlobRecord($recordName, $blob);
        }
    }

    public function archivePeriod()
    {
        $dataTableToSum = $this->getRecordNames();
        $nameToCount = $this->getProcessor()->aggregateDataTableReports($dataTableToSum, $this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, $this->columnToSortByBeforeTruncation);

        $mappingFromArchiveName = array(
            self::METRIC_DISTINCT_SEARCH_ENGINE_RECORD_NAME =>
                array('typeCountToUse' => 'level0',
                      'nameTableToUse' => self::SEARCH_ENGINES_RECORD_NAME,
                ),
            self::METRIC_DISTINCT_KEYWORD_RECORD_NAME       =>
                array('typeCountToUse' => 'level0',
                      'nameTableToUse' => self::KEYWORDS_RECORD_NAME,
                ),
            self::METRIC_DISTINCT_CAMPAIGN_RECORD_NAME      =>
                array('typeCountToUse' => 'level0',
                      'nameTableToUse' => self::CAMPAIGNS_RECORD_NAME,
                ),
            self::METRIC_DISTINCT_WEBSITE_RECORD_NAME       =>
                array('typeCountToUse' => 'level0',
                      'nameTableToUse' => self::WEBSITES_RECORD_NAME,
                ),
            self::METRIC_DISTINCT_URLS_RECORD_NAME          =>
                array('typeCountToUse' => 'recursive',
                      'nameTableToUse' => self::WEBSITES_RECORD_NAME,
                ),
        );

        foreach ($mappingFromArchiveName as $name => $infoMapping) {
            $typeCountToUse = $infoMapping['typeCountToUse'];
            $nameTableToUse = $infoMapping['nameTableToUse'];

            if ($typeCountToUse == 'recursive') {

                $countValue = $nameToCount[$nameTableToUse]['recursive']
                    - $nameToCount[$nameTableToUse]['level0'];
            } else {
                $countValue = $nameToCount[$nameTableToUse]['level0'];
            }
            $this->getProcessor()->insertNumericRecord($name, $countValue);
        }
    }
}