<?php

namespace AhaApp;

use DateTime;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_BatchUpdateSpreadsheetRequest;
use Google_Service_Sheets_ValueRange;

class Aha
{
    public $sheetHeaders;

    protected $products;
    protected $spreadsheetId;
    protected $features;
    protected $ideas;
    protected $parsed;

    const DEBUG = false;
    const EXPORT_DATE_FORMAT = 'm/d/Y';
    const FEATURE_REFERENCE_NUM = 'feature_reference_num';
    const COLUMN_FEATURE = 'feature';
    const COLUMN_REFERENCE_NUM = 'reference_num';
    const IMPORT_DATE_FORMAT = 'Y-m-d';

    /**
     * @var AhaConfig
     */
    protected $config;

    const COLUMN_VALUE = 'value';

    const COLUMN_NAME = 'name';

    const COLUMN_KEY = 'key';

    const COLUMN_IDEAS_COUNTER = 'ideas_counter';

    public function __construct(AhaConfig $config)
    {
        $this->config = $config;
        
        $this->products = [
            'ECO',
            'MID',
            'COS',
            'B2CS',
            'API',
            'TECH',
        ];

        for ($a = 1; $a <= 4; $a++) {
            $this->sheetHeaders['idea_reference_num_' . $a] = 'Idea Reference Number ' . $a;
            $this->sheetHeaders['idea_name_' . $a] = 'Idea Name ' . $a;
            $this->sheetHeaders['client_name_' . $a] = 'Client ' . $a;
            $this->sheetHeaders['cc_due_date_' . $a] = 'Idea Due date ' . $a;
            $this->sheetHeaders['idea_status_' . $a] = 'Idea Status ' . $a;
            $this->sheetHeaders['billable_days_' . $a] = 'Billable days ' . $a;
            $this->sheetHeaders['cc_estimation_' . $a] = 'CC Sales Estimation ' . $a;
        }

        $this->sheetHeaders['feature_name'] = 'Feature Name';
        $this->sheetHeaders['feature_status'] = 'Feature status';
        $this->sheetHeaders['feature_integrations'] = 'Integrations';
        $this->sheetHeaders['feature_start_date'] = 'Feature Start Date';
        $this->sheetHeaders['feature_due_date'] = 'Feature Due Date';
        $this->sheetHeaders[self::COLUMN_IDEAS_COUNTER] = 'IdeasCounter';

        $this->features = [];
        $this->ideas = [];

        $this->parsed = [];

        foreach ($this->products as $product) {
            $this->parsed[$product] = [];
        }

    }

    public function run()
    {
        $this->log('Starting...');
        $this->saveDataToGoogleSheet($this->getData());
        $this->log('Done.');
    }

    /**
     * @return array
     */
    protected function getData()
    {
        $this->log('Loading data...');
        $ideas = [];

        foreach ($this->products as $product) {
            $this->log('Loading '.$product.'...');
            $productData = $this->getProductIdeas([], $product, 1);
            $this->exportOutput($product, $productData);
            $ideas[$product] = [];
            $ideas[$product] = $productData;
        }

        return $this->parsed;

    }

    /**
     * @param array $responseArray
     * @param string $productName
     * @param int $page
     *
     * @return array
     */
    protected function getProductIdeas(array $responseArray, string $productName, int $page)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->config->getToken(),
        ]);

        curl_setopt($ch, CURLOPT_URL, $this->config->getAhaApiUrl() . '/products/' . $productName . '/ideas/?page=' . $page . '&fields=*');
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $serverOutput = curl_exec($ch);

        $response = json_decode($serverOutput, true);

        if ($page < $response['pagination']['total_pages']) {
            $responseArray = $this->getProductIdeas($responseArray, $productName, $page + 1);
        }

        $this->getIdeasData($productName, $response['ideas']);

        curl_close($ch);

        return $responseArray;
    }

    /**
     * @param string $productName
     * @param array $ideas
     */
    protected function getIdeasData(string $productName, array $ideas)
    {
        foreach ($ideas as $idea) {
            $row = [];
            $ideaCounter = 1;
            $rowId = null;

            if (array_key_exists(self::COLUMN_FEATURE, $idea)) {
                $rowId = $idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM];

                if (array_key_exists($idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM], $this->features) === false) {
                    $this->features[$idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM]] = $this->getFeature($idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM]);

                    $this->exportOutput($idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM], $this->features[$idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM]]);
                }

                if ($rowId) {
                    if (array_key_exists($rowId, $this->parsed[$productName]) === false) {
                        $this->parsed[$productName][$rowId] = [];
                        $row[self::COLUMN_IDEAS_COUNTER] = 1;
                        $ideaCounter = 1;
                    } else {
                        $row = $this->parsed[$productName][$rowId];
                        $ideaCounter = $row[self::COLUMN_IDEAS_COUNTER];
                    }
                }

                foreach ($this->features[$idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM]][self::COLUMN_FEATURE]['integration_fields'] as $integrationField) {
                    if ($integrationField[self::COLUMN_NAME] === self::COLUMN_KEY) {
                        $row['feature_integrations'] = $integrationField[self::COLUMN_VALUE];
                        break;
                    }
                }

                $row[self::FEATURE_REFERENCE_NUM] = $idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM];
                $row['feature_name'] = $idea[self::COLUMN_FEATURE][self::COLUMN_REFERENCE_NUM] . ' ' . $idea[self::COLUMN_FEATURE][self::COLUMN_NAME];
                $row['feature_created_at'] = $this->getExportDateString($idea[self::COLUMN_FEATURE]['created_at']);

                if (array_key_exists($row[self::FEATURE_REFERENCE_NUM], $this->features)) {
                    $row['feature_status'] = $this->features[$row[self::FEATURE_REFERENCE_NUM]][self::COLUMN_FEATURE]['workflow_status'][self::COLUMN_NAME];
                    $row['feature_start_date'] = $this->getExportDateString($this->features[$row[self::FEATURE_REFERENCE_NUM]][self::COLUMN_FEATURE]['start_date']);
                    $row['feature_due_date'] = $this->getExportDateString($this->features[$row[self::FEATURE_REFERENCE_NUM]][self::COLUMN_FEATURE]['due_date']);
                }
            }

            $row['idea_reference_num_' . $ideaCounter] = $idea[self::COLUMN_REFERENCE_NUM] . ' ' . $idea[self::COLUMN_NAME];
            $row['idea_name_' . $ideaCounter] = $idea[self::COLUMN_NAME];
            $row['idea_status_' . $ideaCounter] = $idea['workflow_status'][self::COLUMN_NAME];

            if (array_key_exists($idea[self::COLUMN_REFERENCE_NUM], $this->ideas) === false) {
                $this->ideas[$idea[self::COLUMN_REFERENCE_NUM]] = $this->getIdea($idea[self::COLUMN_REFERENCE_NUM]);

                $this->exportOutput($idea[self::COLUMN_REFERENCE_NUM], $this->ideas[$idea[self::COLUMN_REFERENCE_NUM]]);
            }

            if (array_key_exists($idea[self::COLUMN_REFERENCE_NUM], $this->ideas)) {
                foreach ($this->ideas[$idea[self::COLUMN_REFERENCE_NUM]]['idea']['custom_fields'] as $customField) {
                    if ($customField[self::COLUMN_KEY] === 'cc_due_date') {
                        $row['cc_due_date_' . $ideaCounter] = $this->getExportDateString($customField[self::COLUMN_VALUE]);
                        break;
                    }
                }
            }

            foreach ($idea['custom_fields'] as $customField) {
                if ($customField[self::COLUMN_KEY] === 'client_name') {
                    $row['client_name_' . $ideaCounter] = $customField[self::COLUMN_VALUE];
                    continue;
                }

                if ($customField[self::COLUMN_KEY] === 'cc_estimation') {
                    $row['cc_estimation_' . $ideaCounter] = $customField[self::COLUMN_VALUE];
                    continue;
                }

                if ($customField[self::COLUMN_KEY] === 'billable_days') {
                    $row['billable_days_' . $ideaCounter] = $customField[self::COLUMN_VALUE];
                    continue;
                }
            }

            $row[self::COLUMN_IDEAS_COUNTER] = $ideaCounter + 1;

            if (isset($rowId)) {
                $this->parsed[$productName][$rowId] = $row;
            } else {
                $this->parsed[$productName][] = $row;
            }

        }
    }

    /**
     * @param string|null $dateStringValue
     *
     * @return string
     */
    protected function getExportDateString($dateStringValue)
    {
        $dateValue = DateTime::createFromFormat(self::IMPORT_DATE_FORMAT, $dateStringValue);
        return ($dateValue) ? $dateValue->format(self::EXPORT_DATE_FORMAT) : '';
    }

    /**
     * @param array $data
     *
     * @throws \Google_Exception
     */
    protected function saveDataToGoogleSheet(array $data)
    {
        $this->log('Exporting to Google...');
        $client = new Google_Client();
        $client->setApplicationName('Aha API Integration');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig($this->config->getGoogleSecretJson());
        $client->setAccessType('offline');

        $service = new Google_Service_Sheets($client);

        $response = $service->spreadsheets->get($this->config->getExportGoogleSpreadsheetId())->getSheets();

        foreach ($response as $sheetData) {
            if ($sheetData['properties']['title'] === 'Sheet1') {
                continue;
            }

            $body = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => [
                    'deleteSheet' => [
                        'sheetId' => $sheetData['properties']['sheetId'],
                    ],
                ],
            ]);
            $service->spreadsheets->batchUpdate($this->config->getExportGoogleSpreadsheetId(), $body);
        }

        foreach ($this->products as $product) {
            $this->log('Exporting '.$product.'...');

            $body = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => [
                    'addSheet' => [
                        'properties' => [
                            'title' => $product,
                        ],
                    ],
                ],
            ]);

            $service->spreadsheets->batchUpdate($this->config->getExportGoogleSpreadsheetId(), $body);


            $dataSet = [];
            $dataSet[] = array_values($this->sheetHeaders);

            foreach ($data[$product] as $key => $datum) {
                $row = [];
                foreach (array_keys($this->sheetHeaders) as $column) {
                    if (array_key_exists($column, $datum) && strlen($datum[$column]) === 0) {
                        $datum[$column] = '0';
                    }
                    if (array_key_exists($column, $datum) === false) {
                        $datum[$column] = '0';
                    }

                    $row[] = $datum[$column];
                }
                $dataSet[] = $row;
            }

            $body = new Google_Service_Sheets_ValueRange([
                'values' => $dataSet,
            ]);

            $params = [
                'valueInputOption' => 'RAW',
            ];

            $rangeSheet1 = $product . '!A1:Z';
            $service->spreadsheets_values->append($this->config->getExportGoogleSpreadsheetId(), $rangeSheet1, $body, $params);
        }
    }

    /**
     * @param string $method
     * @param array $data
     */
    protected function exportOutput(string $method, array $data)
    {
        if (self::DEBUG) {
            file_put_contents('output/aha/' . $method . '.json', json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    /**
     * @param string $featureReferenceNumber
     *
     * @return array
     */
    protected function getFeature(string $featureReferenceNumber)
    {
        return $this->makeRequest('features', $featureReferenceNumber);
    }

    /**
     * @param string $ideaReferenceNumber
     *
     * @return array
     */
    protected function getIdea(string $ideaReferenceNumber)
    {
        return $this->makeRequest('ideas', $ideaReferenceNumber);
    }
    /**
     * @param string $requestType
     * @param string $requestReferenceNumber
     *
     * @return array
     */
    protected function makeRequest(string $requestType, string $requestReferenceNumber)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->config->getToken(),
        ]);

        curl_setopt($ch, CURLOPT_URL, $this->config->getAhaApiUrl() . '/'.$requestType.'/' . $requestReferenceNumber);
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $serverOutput = curl_exec($ch);

        $response = json_decode($serverOutput, true);

        curl_close($ch);

        return $response;
    }

    protected function log($string)
    {
        print $string.'<br/>'.PHP_EOL;
        flush();
    }

}
