<?php
namespace Stanford\DoceboIntegration;

require 'vendor/autoload.php';

require_once 'classes/doceboClient.php';

use Google\Cloud\SecretManager\V1\Secret;
class DoceboIntegration extends \ExternalModules\AbstractExternalModule {

    private $doceboClient;


    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public function redcap_survey_complete( int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance ) {

    }


    public function getDoceboClient()
    {
        if (!$this->doceboClient) {
            $this->doceboClient = new DoceboClient($this->PREFIX);
        }
        return $this->doceboClient;
    }
}
