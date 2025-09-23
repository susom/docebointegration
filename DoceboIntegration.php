<?php
namespace Stanford\DoceboIntegration;

require 'vendor/autoload.php';

require_once 'classes/doceboClient.php';

use REDCap;

/**
 * Class DoceboIntegration
 *
 * Integration with Docebo for enrolling users into learning plans.
 *
 * @package Stanford\DoceboIntegration
 */
class DoceboIntegration extends \ExternalModules\AbstractExternalModule
{

    const REQUEST_TYPE_USER_TRANING = '3';
    private $doceboClient;

    private $record;

    private $record_id;

    private $requesterData = [
        'email' => 'requester_email',
        'first_name' => 'requester_f_name',
        'last_name' => 'requester_l_name',
        'sid' => 'requester_sunet_sid',
        'affiliate' => 'requester_affiliate'
    ];

    private $traineeData = [
        'email' => 'trainee_email',
        'first_name' => 'trainee_first_name',
        'last_name' => 'trainee_last_name',
        'sid' => 'trainee_sunetid',
        'affiliate' => 'trainee_affiliate'
    ];

    private $user = [];

    private $doceboUser = [];

    private $doceboUserId = null;
    private $learningPlanId;

    /**
     * DoceboIntegration constructor.
     */
    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    /**
     * REDCap hook: called when a survey is completed.
     *
     * @param int $project_id Project ID.
     * @param mixed $record Record identifier.
     * @param string $instrument Instrument name.
     * @param int $event_id Event ID.
     * @param mixed $group_id Data access group ID.
     * @param string $survey_hash Survey hash.
     * @param mixed $response_id Survey response ID.
     * @param mixed $repeat_instance Repeat instance.
     * @return void
     */
    public function redcap_survey_complete(int $project_id, $record, string $instrument, int $event_id, $group_id, string $survey_hash, $response_id, $repeat_instance)
    {
        try {
            if ($instrument == $this->getProjectSetting('e-reg-request-forms')) {
                $this->record_id = $record;
                // Load user data based on whether its for the requester or its on behalf of someone else
                if ($this->isRequestOnbehalfSomeoneElse()) {
                    $this->user = $this->fillUserData($this->traineeData);
                } else {
                    $this->user = $this->fillUserData($this->requesterData);
                }

                if ($this->getRecord()[$this->getFirstEventId()]['request_type'] == self::REQUEST_TYPE_USER_TRANING) {
                    $this->enrollUserInLearningPlan();
                }
            }
        } catch (\Exception $e) {
            REDCap::logEvent("Docebo Integration Error", $e->getMessage(), $record, $project_id);
            return;
        }
    }

    /**
     * Enroll the current user in the configured learning plan.
     *
     * @return void
     * @throws \Exception If the Docebo API call fails.
     */
    private function enrollUserInLearningPlan()
    {
        $this->getDoceboClient()->post("/learningplan/v1/learningplans/{$this->getLearningPlanId()}/enrollments/{$this->getDoceboUserId()}", [
            'status' => 'subscribed'
        ]);
    }

    /**
     * Get the learning plan id for the current record.
     *
     * @return string|int|null Learning plan id.
     */
    private function getLearningPlanId()
    {
        // user_primary_role choices are learning plan ids this way we avoid mapping in EM settings.
        if (!$this->learningPlanId) {
            $this->learningPlanId = $this->getRecord()[$this->getFirstEventId()]['trainee_primary_role'];
        }
        return $this->learningPlanId;
    }

    /**
     * Get the Docebo user id for the current user.
     *
     * @return int|null Docebo user id or null if not set.
     */
    private function getDoceboUserId()
    {
        if (!$this->doceboUserId) {
            $this->getDoceboUser();
        }
        return $this->doceboUserId;
    }

    /**
     * Retrieve the Docebo user for the current user, creating one if necessary.
     *
     * @return array Docebo user data.
     * @throws \Exception If user cannot be found or created.
     */
    private function getDoceboUser()
    {
        if (!$this->doceboUser) {
            $email = $this->user['email'];
            $result = $this->getDoceboClient()->get("/manage/v1/user?search_text=$email");
            if ($result['json']['data']['count'] == 1) {
                $this->doceboUser = $result['json']['data']['items'][0];
                $this->doceboUserId = $this->doceboUser['user_id'];
            } else {
                if ($this->createDoceboUser()) {
                    $this->getDoceboUser();
                } else {
                    throw new \Exception("User with email $email and system could create a user for the email!");
                }
            }
        }
        return $this->doceboUser;
    }

    /**
     * Create a Docebo user for the current user data.
     *
     * @return bool True on success, false on failure.
     */
    private function createDoceboUser()
    {
        try {
            $response = $this->getDoceboUser()->post('/manage/v1/user', [
                'username' => $this->user['email'],
                'email' => $this->user['email'],
                'firstname' => $this->user['first_name'],
                'lastname' => $this->user['last_name'],
                "force_change" => 0,
                "level" => "user",
                "language" => "english",
                "email_validation_status" => 1,
                "valid" => 1,
                "date_format" => null,
                "timezone" => "America/Los_Angeles",
                "role" => null,
                "send_notification_email" => true,
                "can_manage_subordinates" => true
            ]);
            $body = $response->getBody();
            return $body['data']['status'];
        } catch (\Exception $e) {
            REDCap::logEvent("Docebo User Creation Failed", $e->getMessage(), $this->record_id, $this->getProjectId());
        }
        return false;
    }

    /**
     * Retrieve the REDCap record data for the current record id.
     *
     * @return array The record data for the first event.
     */
    private function getRecord()
    {
        if (!$this->record) {
            $param = array(
                'project_id' => $this->getProjectId(),
                'return_format' => 'array',
                'records' => [$this->record_id],
            );
            $this->record = \REDCap::getData($param);
        }
        return $this->record[$this->record_id];
    }

    /**
     * Get the Docebo client instance.
     *
     * @return DoceboClient
     */
    public function getDoceboClient()
    {
        if (!$this->doceboClient) {
            $this->doceboClient = new DoceboClient($this->PREFIX);
        }
        return $this->doceboClient;
    }

    /**
     * Determine if the request is being submitted on behalf of someone else.
     *
     * @return bool True if submitted on behalf of someone else.
     */
    private function isRequestOnbehalfSomeoneElse()
    {
        return $this->getRecord()[$this->getFirstEventId()]['submitting_behalf'];
    }

    /**
     * Fill user data array from record fields mapping.
     *
     * @param array $dataType Mapping of user keys to record field names.
     * @return array Associative array with keys like 'email', 'first_name', 'last_name', etc.
     */
    private function fillUserData($dataType)
    {
        $data = [];
        foreach ($dataType as $key => $field) {
            $data[$key] = $this->getRecord()[$this->getFirstEventId()][$field];
        }
        return $data;
    }
}
