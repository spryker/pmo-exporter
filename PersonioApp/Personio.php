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
    /**
     * @var PersonioConfig
     */
    protected $config;

    const WORKING_HOURS_PER_DAY = 8;

    protected $filteredTimeOffIds;
    protected $filteredTimeOffTypes;
    protected $filteredDepartments;
    protected $filteredEmployeeStatuses;
    protected $filteredTimeOffApprovalStatuses;
    protected $filteredTimeOffMonths;

    protected $availableTimeOffTypes;
    protected $availableTimeOffApprovalStatuses;
    protected $availableDepartments;
    protected $availableEmployeeStatuses;

    protected $employees;
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
    protected $timeOffJiraStoryName;
    protected $timeOffJira;

    const ATTRIBUTES = 'attributes';

    public function __construct(PersonioConfig $config)
    {
        $this->config = $config;
        
        $this->filteredTimeOffIds = [];
        $this->filteredTimeOffTypes = [
            1879, // Training
            1088, // Home office
        ];
        $this->filteredTimeOffApprovalStatuses = [];
        $this->filteredDepartments = [2626, 2624, 114938, 114932, 79782];

        $this->filteredEmployeeStatuses = [];
        $this->filteredTimeOffMonths = $this->getRequiredMonths();

        $this->availableTimeOffTypes = [];
        $this->availableTimeOffApprovalStatuses = [];
        $this->availableDepartments = [];
        $this->availableEmployeeStatuses = [];

        $this->employees = [];

        $this->timeOffJira = [
            'Child sick' => 'ABSENCE-2',
            'Home Office' => 'Home Office',
            'Paid vacation' => 'ABSENCE-3',
            'Sick days' => 'ABSENCE-4',
            'Special Paid Vacation' => 'ABSENCE-3', //same as 'Paid vacation'
        ];
        $this->timeOffJiraStoryName = [
            'Child sick' => 'P_ChildSick_Hours',
            'Home Office' => 'Home Office',
            'Paid vacation' => 'P_Vacation_Hours_PAID',
            'Sick days' => 'P_Sick_Hours',
            'Special Paid Vacation' => 'P_Vacation_Hours_PAID', //same as 'Paid vacation'
        ];

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
        $this->log('Starting...');
        $this->parseTimeOffsToGoogleSheetFormat();
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

            if (count($this->filteredEmployeeStatuses) > 0) {
                if (in_array($value[self::ATTRIBUTES]['status']['value'], $this->filteredEmployeeStatuses) === false) {
                    continue;
                }
            }

            if (count($this->filteredDepartments) > 0) {
                if (in_array($value[self::ATTRIBUTES][self::KEY_DEPARTMENT]['value'][self::ATTRIBUTES]['id'], $this->filteredDepartments) === false) {
                    continue;
                }
            }

            $this->employees[] = $value[self::ATTRIBUTES]['id']['value'];

            $rows[] = [
                'id' => $value[self::ATTRIBUTES]['id']['value'],
                'type' => $value['type'],
                'name' => $value[self::ATTRIBUTES]['first_name']['value'] . ' ' . $value[self::ATTRIBUTES]['last_name']['value'],
                'email' => $value[self::ATTRIBUTES]['email']['value'],
                'status' => $value[self::ATTRIBUTES]['status']['value'],
                self::KEY_DEPARTMENT => $value[self::ATTRIBUTES][self::KEY_DEPARTMENT]['value'][self::ATTRIBUTES]['id'],
                'vacationDayBalance' => $value[self::ATTRIBUTES]['vacation_day_balance']['value'],
            ];
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

        $response = json_decode($server_output, true);

        $rows = [];
        foreach ($response['data'] as $key => $value) {
            $this->availableTimeOffApprovalStatuses[$value[self::ATTRIBUTES]['status']] = $value[self::ATTRIBUTES]['status'];
            $this->availableTimeOffTypes[$value[self::ATTRIBUTES]['time_off_type'][self::ATTRIBUTES]['id']] = $value[self::ATTRIBUTES]['time_off_type'][self::ATTRIBUTES]['name'];

            if (count($this->filteredTimeOffTypes) > 0) {
                if (in_array($value[self::ATTRIBUTES]['time_off_type'][self::ATTRIBUTES]['id'], $this->filteredTimeOffTypes) === true) {
                    continue;
                }
            }

            if (count($this->filteredTimeOffApprovalStatuses) > 0) {
                if (in_array($value[self::ATTRIBUTES]['status'], $this->filteredTimeOffApprovalStatuses) === false) {
                    continue;
                }
            }

            if (count($this->filteredTimeOffMonths) > 0) {
                if (in_array(substr($value[self::ATTRIBUTES]['start_date'], 0, 7), $this->filteredTimeOffMonths) === false) {
                    continue;
                }
            }

            if (in_array($value[self::ATTRIBUTES]['employee'][self::ATTRIBUTES]['id']['value'], $this->employees) === false) {
                continue;
            }

            if (in_array($value[self::ATTRIBUTES]['id'], $this->filteredTimeOffIds) === true) {
                continue;
            }

            $department = '';
            foreach ($users as $user) {
                if ($user['id'] === $value[self::ATTRIBUTES]['employee'][self::ATTRIBUTES]['id']['value']) {
                    $department = $user[static::KEY_DEPARTMENT];
                    break;
                }
            }

            $rows[] = [
                'id' => $value[self::ATTRIBUTES]['id'],
                'request_status' => $value[self::ATTRIBUTES]['status'],
                'start_date' => $value[self::ATTRIBUTES]['start_date'],
                'end_date' => $value[self::ATTRIBUTES]['end_date'],
                'days_count' => $value[self::ATTRIBUTES]['days_count'],
                'time_off_type_id' => $value[self::ATTRIBUTES]['time_off_type'][self::ATTRIBUTES]['id'],
                'time_off_type_name' => $value[self::ATTRIBUTES]['time_off_type'][self::ATTRIBUTES]['name'],
                'employee_name' => $value[self::ATTRIBUTES]['employee'][self::ATTRIBUTES]['first_name']['value'] . ' ' . $value[self::ATTRIBUTES]['employee'][self::ATTRIBUTES]['last_name']['value'],
                'employee_email' => $value[self::ATTRIBUTES]['employee'][self::ATTRIBUTES]['email']['value'],
                'certificate_status' => $value[self::ATTRIBUTES]['certificate']['status'],
                'half_day_start' => $value[self::ATTRIBUTES]['half_day_start'],
                'half_day_end' => $value[self::ATTRIBUTES]['half_day_end'],
                static::KEY_DEPARTMENT => $department,
            ];
        }

        curl_close($ch);

        return $rows;
    }

    protected function parseTimeOffsToGoogleSheetFormat()
    {
        $client = new Google_Client();
        $client->setApplicationName('Personio');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig($this->config->getGoogleSecretJson());
        $client->setAccessType('offline');

        $sheets = new Google_Service_Sheets($client);

        $rangeSheet1 = 'Sheet1!A2:F';
        $spreadsheetId = $this->config->getPersonioSpreadSheet1TokenId();

        $sheets->spreadsheets_values->clear($spreadsheetId, 'Sheet1!A2:G10000', new Google_Service_Sheets_ClearValuesRequest());

        $timeOffs = $this->getTimeOffs();

        $rows = [];

        foreach ($timeOffs as $timeOff) {
            $this->log('Processing '.$timeOff['start_date'].'');
            $date = new DateTime($timeOff['start_date']);
            $dateCount = ceil($timeOff['days_count']);

            if ($dateCount <= 0) {
                continue;
            }

            $prcDates = [];

            for($a = 1 ; $a <= $dateCount ; $a++) {
                $hoursPerDay = self::WORKING_HOURS_PER_DAY;
                $prcDates[] = [
                    'Absences',
                    $this->timeOffJira[$timeOff['time_off_type_name']] . ' ' . $this->timeOffJiraStoryName[$timeOff['time_off_type_name']],
                    $this->timeOffJira[$timeOff['time_off_type_name']],
                    $this->jiraUsers[$timeOff['employee_email']] ?? $timeOff['employee_name'],
                    $date->format('Y-m-d'),
                    $hoursPerDay,
                ];
                $date->modify('+1 day');
                if ($date->format('D') === 'Sat') {
                    $date->modify('+2 day');
                }
            }

            if ($timeOff['half_day_start'] == 1) {
                $prcDates[0][5] = self::WORKING_HOURS_PER_DAY / 2;
            }

            if ($timeOff['half_day_end'] == 1) {
                $prcDates[count($prcDates)-1][5] = self::WORKING_HOURS_PER_DAY / 2;
            }

            foreach ($prcDates as $value) {
                $rows[] = $value;
            }
        }

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
