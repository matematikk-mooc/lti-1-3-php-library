<?php
namespace IMSGlobal\LTI;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use \stdClass;

JWT::$leeway = 5;

class LTI_Message_Launch {

    private $db;
    private $cache;
    private $request;
    private $cookie;
    private $jwt;
    private $registration;
    private $launch_id;

    /**
     * Constructor
     *
     * @param Database  $database   Instance of the database interface used for looking up registrations and deployments.
     * @param Cache     $cache      Instance of the Cache interface used to loading and storing launches. If non is provided launch data will be store in $_SESSION.
     * @param Cookie    $cookie     Instance of the Cookie interface used to set and read cookies. Will default to using $_COOKIE and setcookie.
     */
    function __construct(Database $database, Cache $cache = null, Cookie $cookie = null) {
        $this->db = $database;

        $this->launch_id = uniqid("lti1p3_launch_", true);

        if ($cache === null) {
            $cache = new Cache();
        }
        $this->cache = $cache;

        if ($cookie === null) {
            $cookie = new Cookie();
        }
        $this->cookie = $cookie;
    }

    /**
     * Static function to allow for method chaining without having to assign to a variable first.
     */
    public static function new(Database $database, Cache $cache = null, Cookie $cookie = null) {
        return new LTI_Message_Launch($database, $cache, $cookie);
    }

    /**
     * Load an LTI_Message_Launch from a Cache using a launch id.
     *
     * @param string    $launch_id  The launch id of the LTI_Message_Launch object that is being pulled from the cache.
     * @param Database  $database   Instance of the database interface used for looking up registrations and deployments.
     * @param Cache     $cache      Instance of the Cache interface used to loading and storing launches. If non is provided launch data will be store in $_SESSION.
     *
     * @throws LTI_Exception        Will throw an LTI_Exception if validation fails or launch cannot be found.
     * @return LTI_Message_Launch   A populated and validated LTI_Message_Launch.
     */
    public static function from_cache($launch_id, Database $database, Cache $cache = null) {
        $new = new LTI_Message_Launch($database, $cache, null);
        $new->launch_id = $launch_id;
        $new->jwt = ['body' => $new->cache->get_launch_data($launch_id)];
        return $new->validate_registration();
    }

    /**
     * Validates all aspects of an incoming LTI message launch and caches the launch if successful.
     *
     * @return LTI_Message_Launch   Will return $this if validation is successful.
     * @throws LTI_Exception Will throw an LTI_Exception if validation fails.
     */
    public function validate(bool $skip_state = false) {

        $this->request = $_POST;

        return $this->validate_state($skip_state)
            ->validate_jwt_format()
            ->validate_nonce()
            ->validate_registration()
            ->validate_jwt_signature()
            ->validate_deployment()
            ->validate_message()
            ->cache_launch_data();
    }

    /**
     * Returns whether or not the current launch can use the names and roles service.
     *
     * @return boolean  Returns a boolean indicating the availability of names and roles.
     */
    public function has_nrps() {
        return !empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url']);
    }

    /**
     * Fetches an instance of the names and roles service for the current launch.
     *
     * @return LTI_Names_Roles_Provisioning_Service An instance of the names and roles service that can be used to make calls within the scope of the current launch.
     */
    public function get_nrps() {
        return new LTI_Names_Roles_Provisioning_Service(
            new LTI_Service_Connector($this->registration),
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']);
    }

    /**
     * Returns whether or not the current launch can use the groups service.
     *
     * @return boolean  Returns a boolean indicating the availability of groups.
     */
    public function has_gs() {
        return !empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti-gs/claim/groupsservice']['context_groups_url']);
    }

    /**
     * Fetches an instance of the groups service for the current launch.
     *
     * @return LTI_Course_Groups_Service An instance of the groups service that can be used to make calls within the scope of the current launch.
     */
    public function get_gs() {
        return new LTI_Course_Groups_Service(
            new LTI_Service_Connector($this->registration),
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-gs/claim/groupsservice']);
    }

    /**
     * Returns whether or not the current launch can use the assignments and grades service.
     *
     * @return boolean  Returns a boolean indicating the availability of assignments and grades.
     */
    public function has_ags() {
        return !empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint']);
    }

    /**
     * Fetches an instance of the assignments and grades service for the current launch.
     *
     * @return LTI_Assignments_Grades_Service An instance of the assignments an grades service that can be used to make calls within the scope of the current launch.
     */
    public function get_ags() {
        return new LTI_Assignments_Grades_Service(
            new LTI_Service_Connector($this->registration),
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint']);
    }

    /**
     * Fetches a deep link that can be used to construct a deep linking response.
     *
     * @return LTI_Deep_Link An instance of a deep link to construct a deep linking response for the current launch.
     */
    public function get_deep_link() {
        return new LTI_Deep_Link(
            $this->registration,
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/deployment_id'],
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings']);
    }

    /**
     * Returns whether or not the current launch is a deep linking launch.
     *
     * @return boolean  Returns true if the current launch is a deep linking launch.
     */
    public function is_deep_link_launch() {
        return $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'] === 'LtiDeepLinkingRequest';
    }

    /**
     * Returns whether or not the current launch is a submission review launch.
     *
     * @return boolean  Returns true if the current launch is a submission review launch.
     */
    public function is_submission_review_launch() {
        return $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'] === 'LtiSubmissionReviewRequest';
    }

    /**
     * Returns whether or not the current launch is a resource launch.
     *
     * @return boolean  Returns true if the current launch is a resource launch.
     */
    public function is_resource_launch() {
        return $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'] === 'LtiResourceLinkRequest';
    }

    /**
     * Fetches the decoded body of the JWT used in the current launch.
     *
     * @return array|object Returns the decoded json body of the launch as an array.
     */
    public function get_launch_data()
    {
        return $this->jwt['body'];
    }

    /**
     * Get the unique launch id for the current launch.
     *
     * @return string   A unique identifier used to re-reference the current launch in subsequent requests.
     */
    public function get_launch_id() {
        return $this->launch_id;
    }

    private function get_public_key() {
        $key_set_url = $this->registration->get_key_set_url();

        // Download key set
        $public_key_set = json_decode(
            @file_get_contents(
                $key_set_url,
                false,
                stream_context_create([
                    "ssl" => [
                        "allow_self_signed" => false
                    ]
                ])
            ),
            true
        );

        if (empty($public_key_set)) {
            // Failed to fetch public keyset from URL.
            throw new LTI_Exception("Failed to fetch public key", 1);
        }

        // Find key used to sign the JWT (matches the KID in the header)
        foreach ($public_key_set['keys'] as $key) {
            if (isset($this->jwt['header']['kid']) && $key['kid'] == $this->jwt['header']['kid']) {
                try {
                    $opensslkey = JWK::parseKeySet([
                        'keys' => [$key]
                    ], 'RS256')[$key['kid']];

                    return openssl_pkey_get_details($opensslkey->getKeyMaterial());
                } catch (\Exception $e) {
                    return false;
                }

            }
        }

        // Could not find public key with a matching kid and alg.
        throw new LTI_Exception("Unable to find public key", 1);
    }

    private function cache_launch_data() {
        $this->cache->cache_launch_data($this->launch_id, $this->jwt['body']);
        return $this;
    }

    private function validate_state(bool $skip_state = false) {
        // State check bypass
        if ($skip_state) {
            return $this;
        }

        // Error if state is missing from request
        if (empty($this->request['state'])) {
            throw new LTI_Exception("Missing state", 1);
        }

        // Error if state is missing from cookie
        if (empty($this->cookie->get_cookie('lti1p3_' . $this->request['state']))) {
            throw new LTI_Exception("Missing state cookie", 1);
        }

        // Error if state doesn't match cookie
        if ($this->cookie->get_cookie('lti1p3_' . $this->request['state']) !== $this->request['state']) {
            throw new LTI_Exception("Invalid state", 1);
        }
        return $this;
    }

    private function validate_jwt_format() {
        // Get id_token; throw exception on missing / empty value.
        $jwt = ($this->request['id_token'] ?? null);
        if (empty($jwt)) {
            throw new LTI_Exception("Missing id_token", 1);
        }

        // Get parts of JWT; throw exception on invalid number of parts.
        $jwt_parts = explode('.', $jwt);
        if (count($jwt_parts) !== 3) {
            throw new LTI_Exception("Invalid id_token, JWT must contain 3 parts", 1);
        }

        // Decode JWT headers and body
        $this->jwt['header'] = json_decode(JWT::urlsafeB64Decode($jwt_parts[0]), true);
        $this->jwt['body'] = json_decode(JWT::urlsafeB64Decode($jwt_parts[1]), true);

        return $this;
    }

    private function validate_nonce() {
        if (!$this->cache->check_nonce($this->jwt['body']['nonce'])) {
            //throw new LTI_Exception("Invalid Nonce");
        }
        return $this;
    }

    private function validate_registration() {
        // Find registration.
        $iss = $this->jwt['body']['iss'];
        $this->registration = $this->db->find_registration_by_issuer($iss);
        if (empty($this->registration)) {
            throw new LTI_Exception('Registration not found for issuer (' . $iss . ')', 1);
        }

        $client_id_from_jwt = is_array($this->jwt['body']['aud']) ? $this->jwt['body']['aud'][0] : $this->jwt['body']['aud'];
        $client_id_from_registration = $this->registration->get_client_id();
        if ($client_id_from_jwt !== $client_id_from_registration) {
            throw new LTI_Exception(
                'Client id extracted from JWT (' . $client_id_from_jwt . ') does not match client id from registration ' .
                '(' . $client_id_from_registration . ')',
                1
            );
        }

        return $this;
    }

    private function validate_jwt_signature() {
        // Fetch public key.
        $public_key = $this->get_public_key();

        // Validate JWT signature
        try {
            JWT::decode($this->request['id_token'], new Key( $public_key['key'], 'RS256'));
        } catch (\Exception $e) {
            // Error validating signature.
            print_r($e);
            throw new LTI_Exception("Invalid signature on id_token", 1);
        }

        return $this;
    }

    private function validate_deployment() {
        // Find deployment.
        $deployment = $this->db->find_deployment(
            ($this->jwt['body']['iss'] ?? ''),
            ($this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? '')
        );

        if (empty($deployment)) {
            // deployment not recognized.
            throw new LTI_Exception("Unable to find deployment", 1);
        }

        return $this;
    }

    private function validate_message() {
        if (empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'])) {
            // Unable to identify message type.
            throw new LTI_Exception("Invalid message type", 1);
        }

        // Do message type validation

        // Import all validators
        foreach (glob(__DIR__ . "/Validators/*.php") as $filename) {
            include_once $filename;
        }

        // Create instances of all validators
        $classes = get_declared_classes();
        $validators = array();
        foreach ($classes as $class_name) {
            // Check the class implements message validator
            $reflect = new \ReflectionClass($class_name);
            if ($reflect->implementsInterface('\IMSGlobal\LTI\Message_Validator')) {
                // Create instance of class
                $validators[] = new $class_name();
            }
        }

        $message_validator = false;
        foreach ($validators as $validator) {
            if ($validator->can_validate($this->jwt['body'])) {
                if ($message_validator !== false) {
                    // Can't have more than one validator apply at a time.
                    throw new LTI_Exception("Validator conflict", 1);
                }
                $message_validator = $validator;
            }
        }

        if ($message_validator === false) {
            throw new LTI_Exception("Unrecognized message type.", 1);
        }

        if (!$message_validator->validate($this->jwt['body'])) {
            throw new LTI_Exception("Message validation failed.", 1);
        }

        return $this;

    }
}
?>
