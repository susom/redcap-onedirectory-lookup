<?php
namespace Stanford\RedcapOneDirectoryLookup;

require_once "emLoggerTrait.php";

/**
 * Class RedcapOneDirectoryLookup
 * @package Stanford\RedcapOneDirectoryLookup
 * @property array $fieldsMap
 */
class RedcapOneDirectoryLookup extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $fieldsMap;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
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
                $ins['map'][] = array($k => $v);
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
}
