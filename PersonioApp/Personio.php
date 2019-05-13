<?php

namespace PersonioApp;

use DateTime;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_ClearValuesRequest;
use Google_Service_Sheets_ValueRange;
use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\JiraException;
use JiraRestApi\User\UserService;

class Personio
{

    const ATTRIBUTES = 'attributes';

    /**
     * @var PersonioConfig
     */
    protected $config;

    const WORKING_HOURS_PER_DAY = 8;

    protected $filteredTimeOffIds = [];
    protected $filteredTimeOffTypes = [
        1879, // Training
        1088, // Home office
    ];
    protected $filteredDepartments = [2626, 2624, 114938, 114932, 79782, 79779, 2573, 184022, 114935];
    protected $filteredEmployeeStatuses = [];
    protected $filteredTimeOffApprovalStatuses = [];
    protected $filteredTimeOffMonths;

    protected $availableTimeOffTypes = [];
    protected $availableTimeOffApprovalStatuses = [];
    protected $availableDepartments = [];
    protected $availableEmployeeStatuses = [];
    protected $includeEmployees = [
        697920,//Felix Richard Pfander
        764429,//Juliane Kissau
        116577,//Annika Ulke
    ];

    protected $employees = [];
    protected $processingStatus = null;

    const KEY_TIME_OFF = 'time_off';

    const KEY_TIME_OFF_APPROVAL_STATUS = 'time_off_approval_status';

    const KEY_DEPARTMENT = 'department';

    const KEY_EMPLOYEE_STATUS = 'employee_status';

    const KEY_CLIENT_ID = 'clientId';

    const KEY_CLIENT_SECRET = 'clientSecret';

    const KEY_REQUEST_CLIENT_ID = 'client_id';

    const KEY_REQUEST_CLIENT_SECRET = 'client_secret';

    protected $jiraCredentials;
    protected $jiraUsers;
    protected $timeOffJiraStoryName = [
        'Child sick' => 'P_ChildSick_Hours',
        'Home Office' => 'Home Office',
        'Paid vacation' => 'P_Vacation_Hours_PAID',
        'Sick days' => 'P_Sick_Hours',
        'Special Paid Vacation' => 'P_Vacation_Hours_PAID', //same as 'Paid vacation'
        'Parental leave' => 'P_Parental Leave',
        'Unpaid vacation' => 'P_Vacation_Hours_UNPAID',
        'Training ' => 'Training',
    ];
    protected $timeOffJira= [
        'Child sick' => 'ABSENCE-2',
        'Home Office' => 'Home Office',
        'Paid vacation' => 'ABSENCE-3',
        'Sick days' => 'ABSENCE-4',
        'Special Paid Vacation' => 'ABSENCE-3', //same as 'Paid vacation'
        'Unpaid vacation' => 'ABSENCE-5', //same as 'Paid vacation'
        'Parental leave' => 'ABSENCE-10',
        'Training ' => 'ABSENCE-15',
    ];

    public function __construct(PersonioConfig $config)
    {
        $this->config = $config;
        
        $this->filteredTimeOffMonths = $this->getRequiredMonths();

        $this->jiraCredentials = new ArrayConfiguration([
            'jiraHost' => $this->config->getPersonioJiraHost(),
            'jiraUser' => $this->config->getPersonioJiraUser(),
            'jiraPassword' => $this->config->getPersonioJiraPassword(),
        ]);

        try {
            $us = new UserService($this->jiraCredentials);

            $paramArray = [
                'username' => '_', // get all users.
                'startAt' => 0,
                'maxResults' => 1000,
                'includeInactive' => true,
            ];
            // get the user info.
            $users = $us->findUsers($paramArray);
            foreach ($users as $user) {
                $this->jiraUsers[$user->emailAddress] = $user->displayName;
            }
        } catch (JiraException $e) {
            print("Error Occurred! " . $e->getMessage());
        }
    }

    public function run()
    {
        $this->log('Downloading day offs...');
        $timeOffs = $this->getTimeOffs();

        $this->log('Converting days offs...');
        $rows = $this->convertTimeOffs($timeOffs);

        $this->log('Exporting days offs...');
        $this->exportToGoogleSheetFormat($rows);

        $this->log('Done.');
    }

    public function setSelectedValues()
    {
        if (array_key_exists(self::KEY_TIME_OFF, $_POST)) {
            $this->filteredTimeOffTypes = $_POST[self::KEY_TIME_OFF];
        }
        if (array_key_exists(self::KEY_TIME_OFF_APPROVAL_STATUS, $_POST)) {
            $this->filteredTimeOffApprovalStatuses = $_POST[self::KEY_TIME_OFF_APPROVAL_STATUS];
        }
        if (array_key_exists(self::KEY_DEPARTMENT, $_POST)) {
            $this->filteredDepartments = $_POST[self::KEY_DEPARTMENT];
        }
        if (array_key_exists(self::KEY_EMPLOYEE_STATUS, $_POST)) {
            $this->filteredEmployeeStatuses = $_POST[self::KEY_EMPLOYEE_STATUS];
        }
    }

    public function getProcessingStatus()
    {
        return $this->processingStatus;
    }

    protected function getToken()
    {
        $requestKeys = [
            self::KEY_REQUEST_CLIENT_ID => $this->config->getPersonioApiClientId(),
            self::KEY_REQUEST_CLIENT_SECRET => $this->config->getPersonioApiClientSecret(),
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->config->getPersonioApiUrl() . 'auth');
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($requestKeys));

        // receive server response ...
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec($ch);

        $response = json_decode($server_output, true);

        curl_close($ch);

        return $response['data']['token'];
    }

    protected function getUsersList()
    {
        $token = $this->getToken();

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($ch, CURLOPT_URL, $this->config->getPersonioApiUrl() . 'company/employees');
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec($ch);

        $response = json_decode($server_output, true);

        $rows = [];
        foreach ($response['data'] as $key => $value) {
            $this->availableDepartments[$value[self::ATTRIBUTES][self::KEY_DEPARTMENT]['value'][self::ATTRIBUTES]['id']] = $value[self::ATTRIBUTES][self::KEY_DEPARTMENT]['value'][self::ATTRIBUTES]['name'];
            $this->availableEmployeeStatuses[$value[self::ATTRIBUTES]['status']['value']] = $value[self::ATTRIBUTES]['status']['value'];

            $personInformation = [
                'id' => $value[self::ATTRIBUTES]['id']['value'],
                'type' => $value['type'],
                'name' => $value[self::ATTRIBUTES]['first_name']['value'] . ' ' . $value[self::ATTRIBUTES]['last_name']['value'],
                'email' => $value[self::ATTRIBUTES]['email']['value'],
                'status' => $value[self::ATTRIBUTES]['status']['value'],
                self::KEY_DEPARTMENT => $value[self::ATTRIBUTES][self::KEY_DEPARTMENT]['value'][self::ATTRIBUTES]['id'],
                'vacationDayBalance' => $value[self::ATTRIBUTES]['vacation_day_balance']['value'],
            ];

            if (count($this->filteredEmployeeStatuses) > 0) {
                if (in_array($value[self::ATTRIBUTES]['status']['value'], $this->filteredEmployeeStatuses) === false) {
                    continue;
                }
            }


            if (!in_array($personInformation['id'], $this->includeEmployees)) {

                if (count($this->filteredDepartments) > 0) {
                    if (in_array($value[self::ATTRIBUTES][self::KEY_DEPARTMENT]['value'][self::ATTRIBUTES]['id'], $this->filteredDepartments) === false) {
                        continue;
                    }
                }
            }

            $this->employees[] = $value[self::ATTRIBUTES]['id']['value'];

            $rows[] = $personInformation;
        }

        curl_close($ch);

        return $rows;
    }

    protected function getTimeOffs()
    {
        $token = $this->getToken();
        $users = $this->getUsersList();

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($ch, CURLOPT_URL, $this->config->getPersonioApiUrl() . 'company/time-offs');
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($server_output, true);

        $rows = [];

        foreach ($response['data'] as $key => $value) {
            $attributes = $value[self::ATTRIBUTES];
            
            $this->availableTimeOffApprovalStatuses[$attributes['status']] = $attributes['status'];
            $this->availableTimeOffTypes[$attributes['time_off_type'][self::ATTRIBUTES]['id']] = $attributes['time_off_type'][self::ATTRIBUTES]['name'];

            $department = '';
            foreach ($users as $user) {
                if ($user['id'] === $attributes['employee'][self::ATTRIBUTES]['id']['value']) {
                    $department = $user[static::KEY_DEPARTMENT];

                    break;
                }
            }

            $dayOffInformation = [
                'id' => $attributes['id'],
                'request_status' => $attributes['status'],
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
                'days_count' => $attributes['days_count'],
                'time_off_type_id' => $attributes['time_off_type'][self::ATTRIBUTES]['id'],
                'time_off_type_name' => $attributes['time_off_type'][self::ATTRIBUTES]['name'],
                'employee_name' => $attributes['employee'][self::ATTRIBUTES]['first_name']['value'] . ' ' . $attributes['employee'][self::ATTRIBUTES]['last_name']['value'],
                'employee_email' => $attributes['employee'][self::ATTRIBUTES]['email']['value'],
                'certificate_status' => $attributes['certificate']['status'],
                'half_day_start' => $attributes['half_day_start'],
                'half_day_end' => $attributes['half_day_end'],
                static::KEY_DEPARTMENT => $department,
            ];

            if (count($this->filteredTimeOffTypes) > 0) {
                if (in_array($attributes['time_off_type'][self::ATTRIBUTES]['id'], $this->filteredTimeOffTypes) === true) {
                    print 'filteredTimeOffTypes ' . var_export($attributes).PHP_EOL;
                    continue;
                }
            }

            if (count($this->filteredTimeOffApprovalStatuses) > 0) {
                if (in_array($attributes['status'], $this->filteredTimeOffApprovalStatuses) === false) {
                    print 'filteredTimeOffApprovalStatuses ' . var_export($attributes).PHP_EOL;
                    continue;
                }
            }

            if (count($this->filteredTimeOffMonths) > 0) {
                if (in_array(substr($attributes['start_date'], 0, 7), $this->filteredTimeOffMonths) === false) {
                    print 'filteredTimeOffMonths ' . var_export($attributes).PHP_EOL;
                    continue;
                }
            }

            if (in_array($attributes['employee'][self::ATTRIBUTES]['id']['value'], $this->employees) === false) {
                print 'employee ' . var_export($attributes).PHP_EOL;
                continue;
            }

            if (in_array($attributes['id'], $this->filteredTimeOffIds) === true) {
                print 'filteredTimeOffIds ' . var_export($attributes).PHP_EOL;
                continue;
            }

            $rows[] = $dayOffInformation;
        }

        return $rows;
    }

    protected function convertTimeOffs(array $timeOffs)
    {
        $rows = [];

        foreach ($timeOffs as $timeOff) {
            $this->log('Processing ' . $timeOff['start_date'] . '');
            $startDate = new DateTime($timeOff['start_date']);
            $endDate = new DateTime($timeOff['end_date']);
            if (ceil($timeOff['days_count']) <= 0) {
                continue;
            }

            $prcDates = [];

            $date = $startDate;
            while ($date <= $endDate) {
                $hoursPerDay = self::WORKING_HOURS_PER_DAY;
                $prcDates[] = [
                    'Absences',
                    $this->timeOffJira[$timeOff['time_off_type_name']] . ' ' . $this->timeOffJiraStoryName[$timeOff['time_off_type_name']],
                    $this->timeOffJira[$timeOff['time_off_type_name']],
                    $this->jiraUsers[$timeOff['employee_email']] ?? $timeOff['employee_name'],
                    $date->format('Y-m-d'),
                    $hoursPerDay,
                ];

                if (!isset($this->timeOffJira[$timeOff['time_off_type_name']])) {
                    var_dump($timeOff['time_off_type_name']);
                }
                if (!isset($this->timeOffJiraStoryName[$timeOff['time_off_type_name']])) {
                    var_dump($timeOff['time_off_type_name']);
                }

                $date->modify('+1 day');
                if ($date->format('D') === 'Sat') {
                    $date->modify('+2 day');
                }
            }

            if ($timeOff['half_day_start'] == 1) {
                $prcDates[0][5] = self::WORKING_HOURS_PER_DAY / 2;
            }

            if ($timeOff['half_day_end'] == 1) {
                $prcDates[count($prcDates) - 1][5] = self::WORKING_HOURS_PER_DAY / 2;
            }

            foreach ($prcDates as $value) {
                $rows[] = $value;
            }
        }

        return $rows;
    }

    protected function exportToGoogleSheetFormat(array $rows) {

        $client = new Google_Client();
        $client->setApplicationName('Personio');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig($this->config->getGoogleSecretJson());
        $client->setAccessType('offline');

        $sheets = new Google_Service_Sheets($client);

        $rangeSheet1 = 'Sheet1!A2:F';
        $spreadsheetId = $this->config->getPersonioSpreadSheet1TokenId();

        $sheets->spreadsheets_values->clear($spreadsheetId, 'Sheet1!A2:G10000', new Google_Service_Sheets_ClearValuesRequest());

        $body = new Google_Service_Sheets_ValueRange([
            'values' => $rows,
        ]);

        $params = [
            'valueInputOption' => 'RAW',
        ];
        $sheets->spreadsheets_values->append($spreadsheetId, $rangeSheet1, $body, $params);

        $rangeSheet2 = 'Manual!A2:F';
        $response = $sheets->spreadsheets_values->get($spreadsheetId, $rangeSheet2);

        $body = new Google_Service_Sheets_ValueRange([
            'values' => $response['values'],
        ]);

        $sheets->spreadsheets_values->append($spreadsheetId, $rangeSheet1, $body, $params);
    }

    protected function log($string)
    {
        error_log('Personio: ' . $string);
    }

    protected function getRequiredMonths()
    {
        $targetTime = strtotime(sprintf("+%s month", $this->config->getPersonioAddMonths()));

        $targetMonth = (int)date('m', $targetTime);
        $targetYear = (int)date('Y', $targetTime);
        $firstMonth = 1;
        $firstYear = 2016;

        if ($targetMonth == 1) {
            $firstYear = $firstYear - 1;
        }

        $result = [];

        while ($firstYear < $targetYear || $firstYear === $targetYear && $firstMonth <= $targetMonth) {
            $result[] = sprintf('%d-%02d', $firstYear, $firstMonth);
            $firstYear += (int)floor($firstMonth/12);
            $firstMonth = $firstMonth%12 + 1;
        }

        return $result;
    }
}
