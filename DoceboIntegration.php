<?php
namespace Stanford\DoceboIntegration;

class DoceboIntegration extends \ExternalModules\AbstractExternalModule {
    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public function redcap_survey_complete( int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance ) {

    }


}
