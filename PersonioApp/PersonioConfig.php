<?php

namespace PersonioApp;

class PersonioConfig
{
    /**
     * @return string
     */
    public function getPersonioApiUrl()
    {
        return $this->getEnvVariable('PERSONIO_API_URL', '');
    }

    /**
     * @return string
     */
    public function getPersonioJiraHost()
    {
        return $this->getEnvVariable('PERSONIO_JIRA_HOST', '');
    }

    /**
     * @return string
     */
    public function getPersonioJiraUser()
    {
        return $this->getEnvVariable('PERSONIO_JIRA_USER', '');
    }

    /**
     * @return string
     */
    public function getPersonioJiraPassword()
    {
        return $this->getEnvVariable('PERSONIO_JIRA_PASSWORD', '');
    }

    /**
     * @return string
     */
    public function getPersonioSpreadSheet1TokenId()
    {
        return $this->getEnvVariable('PERSONIO_SPREADSHEET1_ID', '');
    }

    /**
     * @return string
     */
    public function getPersonioApiClientId()
    {
        return $this->getEnvVariable('PERSONIO_API_CLIENT_ID', '');
    }

    /**
     * @return string
     */
    public function getPersonioApiClientSecret()
    {
        return $this->getEnvVariable('PERSONIO_API_CLIENT_SECRET', '');
    }

    /**
     * @return array
     */
    public function getGoogleSecretJson()
    {
        $jsonData = $this->getEnvVariable('GOOGLE_CLIENT_SECRET', '');

        return json_decode($jsonData, true) ?? [];
    }

    /**
     * @param string $variableName
     * @param $defaultValue
     *
     * @return array|false|string
     */
    protected function getEnvVariable(string $variableName, $defaultValue)
    {
        $value = getenv($variableName);

        return $value ? $value : $defaultValue;
    }
}