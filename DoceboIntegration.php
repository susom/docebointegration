<?php

namespace Stanford\DoceboIntegration;

require 'vendor/autoload.php';

require_once 'classes/doceboClient.php';
require_once "emLoggerTrait.php";

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
    use emLoggerTrait;

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

    private $courseEnrollment = [];

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
                    // check if user already enrolled in the learning plan.
                    if (empty($this->getDoceboLearningPlanUserEnrollment())) {
                        $this->enrollUserInLearningPlan();
                    }
                    $this->updateDoceboForm();
                }
            }
        } catch (\Exception $e) {
            REDCap::logEvent("Docebo Integration Error", $e->getMessage(), $record, $project_id);
            return;
        }
    }

    private function getDoceboLearningPlanUserEnrollment()
    {
        if (!$this->courseEnrollment) {
            $result = $this->getDoceboClient()->get("/learningplan/v1/learningplans/{$this->getLearningPlanId()}/courses/enrollments?user_id[]={$this->getDoceboUserId()}");
            if (!empty($result['json']['data']['items'])) {
                $this->courseEnrollment = $result['json']['data']['items'];
            }
        }
        return $this->courseEnrollment;
    }

    private function getInstanceIdForCourseId($course_id)
    {
        $instance = $this->getRecord()['repeat_instances'];
        foreach ($instance[$this->getFirstEventId()][$this->getProjectSetting('docebo-enrollment-form')] as $key => $value) {
            if ($value[$this->getProjectSetting('docebo-course-id-field')] == $course_id) {
                return $key;
            }
        }
        return $this->getAutoInstanceNumber($this->record_id, $this->getFirstEventId(), $this->getProjectSetting('docebo-enrollment-form'));
    }
    private function updateDoceboForm()
    {
        $data = [];
        $project = new \Project($this->getProjectId());
        $data[$project->table_pk] = $this->record_id;
        if ($this->getProjectSetting('docebo-user-id-field') != '') {
            $data[$this->getProjectSetting('docebo-user-id-field')] = $this->getDoceboUserId();
        }
        foreach ($this->getDoceboLearningPlanUserEnrollment() as $course) {
            $data['redcap_repeat_instrument'] = $this->getProjectSetting('docebo-enrollment-form');
            $data['redcap_repeat_instance'] = $this->getInstanceIdForCourseId($course['course_id']);

            if ($this->getProjectSetting('docebo-enrollment-status-field') != '') {
                $data[$this->getProjectSetting('docebo-enrollment-status-field')] = $course['enrollment_status'];
            }
            if ($this->getProjectSetting('docebo-course-id-field') != '') {
                $data[$this->getProjectSetting('docebo-course-id-field')] = $course['course_id'];
            }
            if ($this->getProjectSetting('docebo-course-code-field') != '') {
                $data[$this->getProjectSetting('docebo-course-code-field')] = $course['course_code'];
            }
            if ($this->getProjectSetting('docebo-course-name-field') != '') {
                $data[$this->getProjectSetting('docebo-course-name-field')] = $course['course_name'];
            }
            $response = \REDCap::saveData($this->getProjectId(), 'json', json_encode(array($data)));
            if (!empty($response['errors'])) {
                REDCap::logEvent(implode(",", $response['errors']));
            }
        }
    }

    /**
     * get the next available instance number for a form
     *
     * @param mixed $record_id
     * @return int
     */
    private function getAutoInstanceNumber($record_id, $event_id, $instrument)
    {
        $project = new \Project($this->getProjectId());
        $project_id = $this->getProjectId();


        $arr = array_keys($project->forms[$instrument]['fields']);
        $field_list = "'" . implode("','", $arr) . "'";

        $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($project_id) : "redcap_data";
        $query_string = sprintf(
            "SELECT COALESCE(MAX(IFNULL(instance,1)),0)+1 AS next_instance
        FROM $data_table WHERE
        `project_id` = %u
        AND `event_id` = %u
        AND `record`=%s
        AND `field_name` IN (%s)",
            $project_id, $event_id, checkNull($record_id), $field_list
        );
        $result = db_query($query_string);
        if ($row = db_fetch_assoc($result)) {
            $next_instance = @$row['next_instance'];
            return intval($next_instance);
        }
        throw new \Exception("Error finding the next instance number in project {$this->project_id}, record {$record_id}", 1);
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
            $response = $this->getDoceboClient()->post('/manage/v1/user', [
                'username' => $this->user['email'],
                'email' => $this->user['email'],
                'firstname' => $this->user['first_name'],
                'lastname' => $this->user['last_name'],
                'password' => bin2hex(random_bytes(8)),
                "force_change" => 0,
                # level hardcoded value for user creation
                "level" => "6",
                "language" => "en",
                "email_validation_status" => 1,
                "valid" => 1,
                "timezone" => "America/Los_Angeles",
                "send_notification_email" => true,
                "can_manage_subordinates" => true
            ]);
            return $response['json']['data']['success'] ?? false;
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

    private function getProjectRecords($pid)
    {
        $param = array(
            'project_id' => $pid,
            'return_format' => 'array',
        );
        return \REDCap::getData($param);
    }

    public function updateDoceboEnrollmentInfo()
    {
        // There should only be one project with this enabled
        foreach ($this->framework->getProjectsWithModuleEnabled() as $localProjectId) {
            $_GET['pid'] = $localProjectId;
            $this->setProjectId($localProjectId);
            $this->emDebug("Working on PID: " . $localProjectId);
            $records = $this->getProjectRecords($localProjectId);
            foreach ($records as $record_id => $record) {

                $this->record_id = $record_id;
                $this->record = [$record_id => $record];

                // reset docebo user and course enrollment for each record
                $this->courseEnrollment = [];
                $this->doceboUser = [];
                $this->learningPlanId = null;
                $this->doceboUserId = null;

                if ($this->getRecord()[$this->getFirstEventId()]['request_type'] == self::REQUEST_TYPE_USER_TRANING) {
                    try {
                        // Load user data based on whether its for the requester or its on behalf of someone else
                        if ($this->isRequestOnbehalfSomeoneElse()) {
                            $this->user = $this->fillUserData($this->traineeData);
                        } else {
                            $this->user = $this->fillUserData($this->requesterData);
                        }

                        // check if user already enrolled in the learning plan.
                        if (!empty($this->getDoceboLearningPlanUserEnrollment())) {
                            $this->updateDoceboForm();
                        }
                    } catch (\Exception $e) {
                        REDCap::logEvent("Docebo Integration Error", $e->getMessage(), $record_id, $localProjectId);
                        continue;
                    }
                }
            }
        }
    }
}
