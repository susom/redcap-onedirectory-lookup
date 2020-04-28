<?php
namespace Stanford\RedcapOneDirectoryLookup;

require_once "emLoggerTrait.php";

use GuzzleHttp\Client;

define("ELASTIC_CACHE_URL", "https://onedirectory.stanford.edu/api/onedirectory/_search?size=500");

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
        //search in all fields for all words provided
        $res = $this->getClient()->request('GET', ELASTIC_CACHE_URL, [
            'body' => '{
          "query": {
            "multi_match" : {
              "query":      "' . $term . '",
              "type":       "most_fields",
              "fields":     [ "first_name", "last_name", "fullname", "email", "affiliate", "title", "suid" ]
            }
          }
        }'
        ]);


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
                if ($item->_source->affiliate == "Stanford University") {
                    $image = $this->getUrl('assets/images/stanford_university.png', false, false);
                } else {
                    $image = $this->getUrl('assets/images/stanford_medicine.png', false, false);;
                }

                $result[] = array(
                    'id' => $item->_id,
                    'label' => $item->_source->fullname,
                    'title' => $item->_source->title,
                    'suid' => $item->_source->suid,
                    'value' => $item->_source->fullname,
                    'array' => $item->_source,
                    'image' => $image
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
            $ins['alert-if-exist'] = $instance['alert-if-exist'];
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
}
