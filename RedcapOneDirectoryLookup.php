<?php
namespace Stanford\RedcapOneDirectoryLookup;

require_once "emLoggerTrait.php";

use GuzzleHttp\Client;

define("ELASTIC_CACHE_URL", "https://onedirectory.stanford.edu/api/onedirectory/_search?q=");

/**
 * Class RedcapOneDirectoryLookup
 * @package Stanford\RedcapOneDirectoryLookup
 * @property array $fieldsMap
 * @property \GuzzleHttp\Client $client
 */
class RedcapOneDirectoryLookup extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $fieldsMap;

    private $client;


    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
        $this->setClient(new Client);
    }

    public function searchUsers($term)
    {
        $res = $this->getClient()->request('GET', ELASTIC_CACHE_URL . $term);


        return $this->processOneDirectoryResponse($res->getBody()->getContents());
    }

    /**
     *
     */
    private function processOneDirectoryResponse($response)
    {
        $response = json_decode($response);
        $result = array();
        if ($response->hits->total > 0) {
            foreach ($response->hits->hits as $item) {
                $result[] = array(
                    'id' => $item->_id,
                    'label' => $item->_source->fullname,
                    'value' => $item->_source->fullname,
                    'array' => $item->_source
                );
            }
        }
        return $result;
    }

    private function processInstances()
    {
        $instances = $this->getSubSettings('instance');

        $one = $this->getProjectSetting("one-directory-attribute");
        $map = $this->getProjectSetting("mapped-field");
        $fieldMap = $this->getFieldsMap();
        foreach ($instances as $index => $instance) {
            $ins = array();
            $ins['search-field'] = $instance['search-field'];
            foreach ($instance['attribute_instance'] as $a_index => $attribute) {
                $k = $one[$index][$a_index];
                $v = $map[$index][$a_index];
                $ins['map'][$k] = $v;
            }
            $fieldMap[] = $ins;
        }
        $this->setFieldsMap($fieldMap);
    }

    private function processFields()
    {
        $this->processInstances();

        $this->includeFile('view/fields.php');
    }

    public function redcap_data_entry_form_top($version)
    {
        $this->processFields();
    }


    public function redcap_survey_page_top($version, $project_id)
    {
        $this->processFields();
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
    public function getClient(): \GuzzleHttp\Client
    {
        return $this->client;
    }

    /**
     * @param \GuzzleHttp\Client $client
     */
    public function setClient(\GuzzleHttp\Client $client): void
    {
        $this->client = $client;
    }
}
