<?php
namespace Stanford\RedcapOneDirectoryLookup;

require_once "emLoggerTrait.php";

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

    private $fieldsMap;

    private $client;

    private $serverURL = '';


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
     */
    public function searchUsers($term)
    {
        // Build search object
        $headers = [
          'Content-Type' => 'application/json'
        ];
        $body = '{
          "query": {
            "multi_match": {
              "query": "'.$term.'"
            }
          }
        }';

        // Tried other searches but wouldn't return sunet lookup or email lookups.  I think
        // those fields don't have indexing configured so it can't do much

        // $search->query->multi_match->term   = "most_fields";
        // $search->query->multi_match->fields = [ "first_name", "last_name", "fullname", "email", "affiliate", "title", "suid" ];

        // Do a search
        $q = $this->getClient()->post($this->getServerURL(), [
            'body' => $body,
            'headers' => $headers,
        ]);

        $result = $q->getBody()->getContents();
        return $this->processOneDirectoryResponse($result);
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
                    $image = $this->getUrl('assets/images/stanford_university.png', true, true);
                } else {
                    $image = $this->getUrl('assets/images/stanford_medicine.png', true, true);;
                }

                $result[] = array(
                    'id'    => $item->_id,
                    'label' => $item->_source->fullname,
                    'title' => $item->_source->title,
                    'suid'  => $item->_source->suid,
                    'value' => $item->_source->fullname,
                    'array' => $item->_source,
                    'image' => $image
                );
            }
        }
        return $result;
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


}
