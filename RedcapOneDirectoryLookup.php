<?php
namespace Stanford\RedcapOneDirectoryLookup;

require_once "vendor/autoload.php";
require_once "classes/GoogleSecretManager.php";
require_once "classes/MSGraphClient.php";
require_once "emLoggerTrait.php";

use Google\ApiCore\ApiException;
use GuzzleHttp\Client;

# trigger build
/**
 * Class RedcapOneDirectoryLookup
 * @package Stanford\RedcapOneDirectoryLookup
 * @property array $fieldsMap
 * @property \GuzzleHttp\Client $client
 */
class RedcapOneDirectoryLookup extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $secretManager;
    private $fieldsMap;

    private $client;

    private $serverURL = '';

    private $msGraphClient;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
        $this->setClient(new Client);
    }


    public function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        $this->processFields($instrument);
    }


    public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $this->processFields($instrument);
    }


    /**
     * Perform a OneDirectory Search
     * @param $term
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws ApiException
     */
    public function searchUsers($term, $nextPage)
    {
        // Build search object
        return $this->getMSGraphClient()->searchUsers($term, $nextPage);
    }



    /**
     * Loop through config and set the $fieldMap which will be passed through to the javascript code
     */
    private function processInstances()
    {
        $instances = $this->getSubSettings('instance');

        // Array of all lookup instances then the values from the lookup result being used
        $lookup_result_attributes = $this->getProjectSetting("one-directory-attribute");

        // Matching array of fields in the project where the lookup results will be placed
        $lookup_result_fields = $this->getProjectSetting("mapped-field");

        $fieldMap = $this->getFieldsMap();
        foreach ($instances as $index => $instance) {
            $ins = array();
            $ins['search-field']   = $instance['search-field'];
            $ins['alert-if-exist'] = $instance['alert-if-exist'];
            foreach ($instance['attribute_instance'] as $a_index => $attribute) {
                $k = $lookup_result_attributes[$index][$a_index];
                $v = $lookup_result_fields[$index][$a_index];
                $ins['map'][$k] = $v;
            }
            $fieldMap[] = $ins;
        }
        $this->setFieldsMap($fieldMap);
    }


    private function processFields($instrument)
    {
        $this->processInstances();

        // Determine if search-fields are in instrument
        $fields = [];
        foreach ($this->fieldsMap as $instance) array_push($fields, $instance['search-field']);
        $instrument_fields = \REDCap::getFieldNames($instrument);
        if (count(array_intersect($fields, $instrument_fields)) > 0) {
            $this->includeFile('view/fields.php');
        }
    }


    /**
     * @return array
     */
    public function getFieldsMap()
    {
        return $this->fieldsMap;
    }


    /**
     * @param array $fieldsMap
     */
    public function setFieldsMap($fieldsMap)
    {
        $this->fieldsMap = $fieldsMap;
    }


    /**
     * @param string $path
     */
    public function includeFile($path)
    {
        include_once $path;
    }


    /**
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        return $this->client;
    }


    /**
     * @param \GuzzleHttp\Client $client
     */
    public function setClient(\GuzzleHttp\Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return string
     */
    public function getServerURL(): string
    {
        if(!$this->serverURL){
            $this->setServerURL($this->getSystemSetting('onedirectory-url'));
        }
        return $this->serverURL;
    }

    /**
     * @param string $serverURL
     */
    public function setServerURL(string $serverURL): void
    {
        $this->serverURL = $serverURL;
    }

    private function getSecretManager(): GoogleSecretManager
    {
        if (!$this->secretManager) {
            $this->secretManager = new GoogleSecretManager(
                $this->getSystemSetting('google-cloud-project-id'),
                $this->getSystemSetting('google-cloud-service-account-key')
            );
        }
        return $this->secretManager;
    }

    public function getMSGraphClient(): MSGraphClient
    {
        if (!$this->msGraphClient) {
            $this->msGraphClient = new MSGraphClient($this->getSecretManager(), $this);
        }
        return $this->msGraphClient;
    }
}
