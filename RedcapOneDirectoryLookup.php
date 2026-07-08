<?php
namespace Stanford\RedcapOneDirectoryLookup;

require_once "vendor/autoload.php";
require_once "classes/GoogleSecretManager.php";
require_once "classes/MSGraphClient.php";
require_once "emLoggerTrait.php";

use GuzzleHttp\Client;

# trigger build
/**
 * REDCap External Module: OneDirectory Lookup
 *
 * Provides on‑form/person search against Microsoft Graph (app‑only) and maps
 * selected attributes back to REDCap fields based on per‑project configuration.
 *
 * Responsibilities:
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
    private $secretManager = null;

    /**
     * Whether the current page render is a survey page (set in redcap_survey_page_top).
     *
     * @var bool
     */
    private $isSurvey = false;

    /**
     * Survey hash for the current survey page render (set in redcap_survey_page_top).
     *
     * @var string|null
     */
    private $surveyHash = null;

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
        // Remember the survey context so the injected JS can route lookup requests
        // through /webauth (carrying the Shibboleth identity) and pass the survey hash.
        $this->isSurvey = true;
        $this->surveyHash = $survey_hash;
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
    public function searchUsers($term, $nextPage, $companyName, ?array $allowedAttributes = null)
    {
        // Build search object
        return $this->getMSGraphClient()->searchUsers($term, $nextPage, $companyName, $allowedAttributes);
    }


    /**
     * Return the set of OneDirectory attributes that are mapped to REDCap fields for a
     * project, so the AJAX response can be limited to only those attributes.
     *
     * If a search field name is given, only the attributes mapped for that field's
     * instance are returned; if that yields nothing (or no field is given), the union of
     * all mapped attributes for the project is returned. The mapping is read from saved
     * project configuration — never from client input — so a caller cannot request more.
     *
     * @param string|null $searchField REDCap field name of the lookup input (optional).
     * @param int|string|null $projectId Project to read configuration from.
     * @return string[] Distinct mapped attribute keys (e.g., 'mail', 'jobTitle', 'manager.mail').
     */
    public function getMappedAttributesForProject($searchField = null, $projectId = null): array
    {
        $instances = $this->getSubSettings('instance', $projectId);
        $mappedAttributes = $this->getProjectSetting('one-directory-attribute', $projectId); // [instanceIdx][attrIdx]

        if (!is_array($instances) || !is_array($mappedAttributes)) {
            return [];
        }

        $collect = function ($index) use ($mappedAttributes) {
            $attrs = $mappedAttributes[$index] ?? null;
            return is_array($attrs) ? array_values($attrs) : [];
        };

        $matched = [];
        $union = [];
        foreach ($instances as $index => $instance) {
            $attrs = $collect($index);
            $union = array_merge($union, $attrs);
            if ($searchField !== null && $searchField !== '' && ($instance['search-field'] ?? null) === $searchField) {
                $matched = array_merge($matched, $attrs);
            }
        }

        $result = !empty($matched) ? $matched : $union;
        $result = array_filter($result, static fn($a) => is_string($a) && $a !== '');
        return array_values(array_unique($result));
    }


    /**
     * Whether the current page render is a survey page.
     *
     * @return bool
     */
    public function getIsSurvey(): bool
    {
        return $this->isSurvey;
    }


    /**
     * Survey hash for the current survey page render (null on non-survey pages).
     *
     * @return string|null
     */
    public function getSurveyHash(): ?string
    {
        return $this->surveyHash;
    }


    /**
     * Read the Shibboleth-authenticated user id from the web server.
     *
     * Only $_SERVER['REMOTE_USER'] is trusted: it is set by Apache/mod_shib for requests
     * that pass through the protected /webauth location. Client-supplied headers (which
     * would land in $_SERVER['HTTP_*']) are intentionally ignored.
     *
     * @return string
     */
    public function getRemoteUser(): string
    {
        $u = $_SERVER['REMOTE_USER'] ?? '';
        return is_string($u) ? trim($u) : '';
    }


    /**
     * Whether this is a REDCap development server.
     *
     * Gated solely on the server-controlled $GLOBALS['is_development_server'] flag.
     * The HTTP Host header is deliberately NOT consulted (an attacker can spoof it to
     * trigger a dev bypass in production).
     *
     * @return bool
     */
    public static function isDevServer(): bool
    {
        return isset($GLOBALS['is_development_server']) && (string)$GLOBALS['is_development_server'] === '1';
    }


    /**
     * Pure authorization decision for a lookup request.
     *
     * Order of evaluation:
     *  1. A full REDCap session (data-entry form, logged-in survey) is always allowed.
     *  2. Otherwise the request MUST resolve to a valid survey of a project where this
     *     module is enabled — a lookup is never permitted outside a form/survey context.
     *  3. Within that survey context, a Shibboleth (webauth) identity is allowed.
     *  4. Within that survey context, a development server may allow the call without
     *     webauth, but only when explicitly opted in ($devBypassEnabled). A dev flag alone
     *     is never enough, and it can never bypass the survey-context requirement.
     *
     * Kept side-effect free so it can be unit tested.
     *
     * @param bool        $isAuthenticated               framework->isAuthenticated()
     * @param string|null $redcapUser                    Current REDCap username (if any)
     * @param string|null $remoteUser                    Shibboleth REMOTE_USER (if any)
     * @param array|null  $surveyContext                 Result of Survey::getSurveyContextFromSurveyHash()
     * @param bool        $moduleEnabledOnContextProject Whether this module is enabled on the survey's project
     * @param bool        $isDev                         Whether this is a development server
     * @param bool        $devBypassEnabled              Whether the anonymous dev bypass has been explicitly enabled
     * @return array{allow:bool, identity:?string, source:string, reason:string}
     */
    public static function decideLookupAccess(
        bool $isAuthenticated,
        ?string $redcapUser,
        ?string $remoteUser,
        ?array $surveyContext,
        bool $moduleEnabledOnContextProject,
        bool $isDev,
        bool $devBypassEnabled = false
    ): array {
        // 1. A full REDCap session (data-entry form or logged-in survey) is always allowed.
        if ($isAuthenticated) {
            return ['allow' => true, 'identity' => $redcapUser ?: 'redcap-user', 'source' => 'redcap', 'reason' => 'authenticated'];
        }

        // 2. Every unauthenticated request must resolve to a valid survey of a module-enabled
        //    project. A lookup is NEVER permitted outside a form/survey context.
        if (!is_array($surveyContext) || empty($surveyContext['project_id']) || !$moduleEnabledOnContextProject) {
            return ['allow' => false, 'identity' => null, 'source' => 'none', 'reason' => 'invalid-survey-context'];
        }

        // 3. Within that context, the respondent may authenticate via Shibboleth (webauth).
        $remoteUser = is_string($remoteUser) ? trim($remoteUser) : '';
        if ($remoteUser !== '') {
            return ['allow' => true, 'identity' => $remoteUser, 'source' => 'webauth', 'reason' => 'webauth-authenticated'];
        }

        // 4. Development-server convenience: skip webauth within a valid survey context only,
        //    and only when explicitly opted in. A dev flag alone can never open access, and
        //    this can never bypass the survey-context requirement above.
        if ($isDev && $devBypassEnabled) {
            return ['allow' => true, 'identity' => 'dev-bypass', 'source' => 'dev', 'reason' => 'dev-server-optin'];
        }

        // 5. Valid context, but no webauth identity and no opted-in dev bypass.
        return ['allow' => false, 'identity' => null, 'source' => 'none', 'reason' => 'webauth-required'];
    }


    /**
     * Authorize the current lookup request, gathering the live inputs for decideLookupAccess().
     *
     * @return array{allow:bool, identity:?string, source:string, reason:string, projectId:mixed}
     */
    public function authorizeLookup(): array
    {
        $isAuth = $this->framework->isAuthenticated();

        $redcapUser = null;
        if ($isAuth) {
            $user = $this->framework->getUser();
            $redcapUser = $user ? $user->getUsername() : null;
        }

        $surveyContext = null;
        $enabledOnProject = false;
        $hash = isset($_GET['survey_hash']) ? (string)$_GET['survey_hash'] : '';
        if (!$isAuth && $hash !== '' && class_exists('\Survey')) {
            $surveyContext = \Survey::getSurveyContextFromSurveyHash($hash);
            if (is_array($surveyContext) && !empty($surveyContext['project_id'])) {
                $enabledOnProject = $this->framework->isModuleEnabled($this->PREFIX, (int)$surveyContext['project_id']);
            }
        }

        $decision = self::decideLookupAccess(
            $isAuth,
            $redcapUser,
            $this->getRemoteUser(),
            $surveyContext,
            $enabledOnProject,
            self::isDevServer(),
            $this->isDevAnonymousBypassEnabled()
        );

        // The survey's project (from the validated hash) is authoritative over any URL pid.
        $decision['projectId'] = $surveyContext['project_id'] ?? $this->getProjectId();

        // Observability: record WHY a lookup was allowed (or denied) so the behavior of the
        // is_development_server flag and the webauth path is visible while testing. Note that
        // an authenticated REDCap session ("source":"redcap") is allowed regardless of the
        // development-server flag; that flag only gates the anonymous survey bypass, which
        // additionally requires the "allow-dev-anonymous-bypass" opt-in. Only emitted when
        // debug logging is enabled.
        $this->emDebug('Lookup authorization decision', [
            'allow'            => $decision['allow'],
            'source'           => $decision['source'],
            'reason'           => $decision['reason'],
            'isAuthenticated'  => $isAuth,
            'isDevServer'      => self::isDevServer(),
            'devBypassEnabled' => $this->isDevAnonymousBypassEnabled(),
            'hasRemoteUser'    => $this->getRemoteUser() !== '',
        ]);

        return $decision;
    }


    /**
     * Whether the anonymous development bypass has been explicitly opted into.
     *
     * This is only consulted on development servers (see decideLookupAccess()); on a
     * production server it has no effect. Defaults to false so the bypass is off unless
     * an administrator deliberately enables it.
     *
     * @return bool
     */
    public function isDevAnonymousBypassEnabled(): bool
    {
        return (int)$this->getSystemSetting('allow-dev-anonymous-bypass') === 1;
    }


    /**
     * The configured per-minute survey lookup rate limit (default 30).
     *
     * @return int
     */
    public function getSurveyLookupRateLimit(): int
    {
        $limit = (int)$this->getSystemSetting('survey-lookup-rate-limit');
        return $limit > 0 ? $limit : 30;
    }


    /**
     * Whether the given identity has exceeded the per-minute limit for a lookup action.
     *
     * Each action uses its own throttle bucket (keyed by $message) so a high-volume,
     * low-sensitivity action (e.g. profile-photo preloads, which fan out to one request
     * per search result) does not exhaust the budget of another action. The counter is
     * driven by the audit-log rows written via logLookupAction() with the same $message.
     *
     * Only meaningful for the unauthenticated survey (webauth/dev) path; logged-in REDCap
     * users are not throttled here.
     *
     * @param string $message  Throttle/audit bucket key (e.g. 'odlookup_manager').
     * @param string $identity Authenticated identity (SUNet / REDCap user).
     * @param int    $limit    Max occurrences allowed within the 60s window.
     * @return bool True if the identity is over the limit and should be blocked.
     */
    public function isActionRateLimited(string $message, string $identity, int $limit): bool
    {
        if ($limit <= 0) {
            $limit = 30;
        }
        return $this->throttle("message = ? and username = ?", [$message, $identity], 60, $limit);
    }


    /**
     * Write an audit log entry (also the throttle counter) attributing a lookup action
     * to a specific identity.
     *
     * @param string $message  Throttle/audit bucket key (e.g. 'odlookup_manager').
     * @param string $identity Authenticated identity (SUNet / REDCap user).
     * @param string $source   'webauth' | 'dev' | 'redcap'
     * @param string $detail   Compact, already-safe detail (search term or Graph user id).
     * @return void
     */
    public function logLookupAction(string $message, string $identity, string $source, string $detail): void
    {
        try {
            $this->log($message, [
                'username'  => $identity,
                'od_source' => $source,
                'od_term'   => mb_substr($detail, 0, 100),
            ]);
        } catch (\Throwable $e) {
            $this->emError('[RedcapOneDirectoryLookup] Failed to write lookup audit log: ' . $e->getMessage());
        }
    }


    /**
     * Whether the given identity has exceeded the per-minute search rate limit.
     *
     * @param string $identity
     * @return bool True if the identity is over the limit and should be blocked.
     */
    public function isLookupRateLimited(string $identity): bool
    {
        return $this->isActionRateLimited('odlookup_search', $identity, $this->getSurveyLookupRateLimit());
    }


    /**
     * Write an audit log entry attributing a search to a specific identity.
     *
     * @param string $identity  Authenticated identity (SUNet / REDCap user).
     * @param string $source    'webauth' | 'dev' | 'redcap'
     * @param string $term      The (already-sanitized) search term.
     * @return void
     */
    public function logLookup(string $identity, string $source, string $term): void
    {
        $this->logLookupAction('odlookup_search', $identity, $source, $term);
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
            // Affiliation enforcement settings (optional per-instance)
            $ins['enforce-affiliation'] = $instance['enforce-affiliation'] ?? null;
            $ins['affiliation-enforcement-source'] = $instance['affiliation-enforcement-source'] ?? null;
            $ins['affiliation-em-value'] = $instance['affiliation-em-value'] ?? null;
            $ins['affiliation-survey-field'] = $instance['affiliation-survey-field'] ?? null;
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

    private function getSecretManager()
    {
        if (!$this->secretManager) {
            $this->secretManager = new GoogleSecretManager(
                $this->getSystemSetting('google-cloud-project-id'),
                '',
                $this
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
