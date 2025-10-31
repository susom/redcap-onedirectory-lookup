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
 * REDCap External Module: OneDirectory Lookup
 *
 * Provides on‑form/person search against Microsoft Graph (app‑only) and maps
 * selected attributes back to REDCap fields based on per‑project configuration.
 *
 * Responsibilities:
 * - Reads Google Secret Manager for Graph client credentials.
 * - Instantiates a reusable MSGraphClient for user search and preview cards.
 * - Processes EM sub‑settings to build a field mapping used by the client UI.
 * - Injects the lookup UI on Data Entry Form and Survey pages.
 *
 * Hooks implemented:
 * - redcap_data_entry_form_top
 * - redcap_survey_page_top
 *
 * @package Stanford\RedcapOneDirectoryLookup
 *
 * @property array                 $fieldsMap   Computed map of search fields and destination fields.
 * @property \GuzzleHttp\Client    $client      Guzzle client used for auxiliary HTTP calls.
 */
class RedcapOneDirectoryLookup extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    /**
     * Lazy-initialized Google Secret Manager wrapper.
     *
     * @var GoogleSecretManager|null
     */
    private $secretManager;

    /**
     * Per-instance mapping of OneDirectory attributes to REDCap destination fields.
     *
     * @var array|null
     */
    private $fieldsMap;

    /**
     * Guzzle HTTP client for network operations unrelated to Graph SDK.
     *
     * @var \GuzzleHttp\Client|null
     */
    private $client;

    /**
     * Base server URL for OneDirectory (configured at the system level).
     *
     * @var string
     */
    private $serverURL = '';

    /**
     * Lazy-initialized Microsoft Graph client helper.
     *
     * @var MSGraphClient|null
     */
    private $msGraphClient;

    /**
     * Module constructor.
     *
     * Initializes a default Guzzle client; other dependencies are created lazily.
     */
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
     * Perform an app-only Microsoft Graph user search via the helper client.
     *
     * @param string      $term     Search term (e.g., SUNet, email prefix, name).
     * @param string|null $nextPage Optional absolute @odata.nextLink for pagination.
     * @return array                 Normalized response from MSGraphClient::searchUsers().
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws ApiException
     */
    public function searchUsers($term, $nextPage)
    {
        // Build search object
        return $this->getMSGraphClient()->searchUsers($term, $nextPage);
    }



    /**
     * Build $fieldsMap from EM sub-settings.
     *
     * Reads these per-instance arrays:
     * - one-directory-attribute : Graph attribute keys to read from results
     * - mapped-field            : REDCap field names to populate with attribute values
     *
     * Populates an internal structure consumed by the injected view/JS.
     *
     * @return void
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


    /**
     * Determine if the current instrument contains any configured search fields
     * and, if so, include the lookup UI.
     *
     * @param string $instrument
     * @return void
     */
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
     * Get the computed fieldsMap structure used by the front-end.
     *
     * @return array|null
     */
    public function getFieldsMap()
    {
        return $this->fieldsMap;
    }


    /**
     * Set the computed fieldsMap.
     *
     * @param array $fieldsMap
     * @return void
     */
    public function setFieldsMap($fieldsMap)
    {
        $this->fieldsMap = $fieldsMap;
    }


    /**
     * Include a PHP view file relative to the module directory.
     *
     * @param string $path
     * @return void
     */
    public function includeFile($path)
    {
        include_once $path;
    }


    /**
     * Get the Guzzle HTTP client instance.
     *
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        return $this->client;
    }


    /**
     * Set/override the Guzzle HTTP client instance.
     *
     * @param \GuzzleHttp\Client $client
     * @return void
     */
    public function setClient(\GuzzleHttp\Client $client)
    {
        $this->client = $client;
    }

    /**
     * Get the configured OneDirectory base URL.
     *
     * Reads the system setting `onedirectory-url` on first access and caches it.
     *
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
     * Set the OneDirectory base URL (used primarily for testing).
     *
     * @param string $serverURL
     * @return void
     */
    public function setServerURL(string $serverURL): void
    {
        $this->serverURL = $serverURL;
    }

    /**
     * Lazily create (and cache) the Google Secret Manager helper using system settings.
     *
     * @return GoogleSecretManager
     */
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

    /**
     * Lazily create (and cache) the Microsoft Graph client helper.
     *
     * @return MSGraphClient
     */
    public function getMSGraphClient(): MSGraphClient
    {
        if (!$this->msGraphClient) {
            $this->msGraphClient = new MSGraphClient($this->getSecretManager(), $this);
        }
        return $this->msGraphClient;
    }
}
