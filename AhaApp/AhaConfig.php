<?php

namespace AhaApp;

class AhaConfig
{
    /**
     * @return string
     */
    public function getToken()
    {
        return $this->getEnvVariable('AHA_TOKEN', '');
    }

    /**
     * @return string
     */
    public function getExportGoogleSpreadsheetId()
    {
        return $this->getEnvVariable('AHA_EXPORT_GOOGLE_SPREADSHEET_ID', '');
    }

    /**
     * @return array
     */
    public function getGoogleSecretJson()
    {
        $jsonData = $this->getEnvVariable('AHA_GOOGLE_CLIENT_SECRET', '');

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

    /**
     * @return string
     */
    public function getAhaApiUrl()
    {
        return $this->getEnvVariable('AHA_API_URL', '');
    }
}