<?php

namespace Stanford\RedcapOneDirectoryLookup;


use Google\ApiCore\ApiException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetQueryParameters;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetRequestConfiguration;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Abstractions\HttpMethod;
use Microsoft\Graph\Generated\Models\UserCollectionResponse;

/**
 * Microsoft Graph client helper for app-only lookups and user search.
 *
 * This class wraps the Microsoft Graph PHP SDK (Kiota) and provides:
 * - Initialization via client credentials loaded from Google Secret Manager.
 * - User search with robust `$select` defaults and `$expand=manager`.
 * - Transparent handling of Graph pagination using `@odata.nextLink`.
 * - Lightweight user normalization for UI consumption (preview cards).
 *
 * Typical usage:
 * ```php
 * $graph = (new MSGraphClient($gsm, $module))->getGraphClient();
 * $results = $ms->searchUsers('jdoe');
 * ```
 *
 * Environment / Secrets (stored in Google Secret Manager):
 * - MS_GRAPH_TENANT_ID
 * - MS_GRAPH_CLIENT_ID
 * - MS_GRAPH_CLIENT_SECRET
 *
 * @package Stanford\RedcapOneDirectoryLookup
 */
class MSGraphClient
{
    private $companyNameMap = [
        '1' => "Stanford Children's Health",
        '2' => 'Stanford Health Care',
        '3' => 'Stanford University',
    ];

    /**
     * Compute mailNickname based on Stanford org rules:
     * - Stanford University or Stanford Children's Health: username part of `mail`
     * - Stanford Health Care: username part of `userPrincipalName`
     */
    private function computeMailNickname(?string $companyName, ?string $mail, ?string $userPrincipalName): ?string
    {
        $company = trim((string)($companyName ?? ''));

        $source = null;
        if ($company === 'Stanford University' || $company === "Stanford Children's Health") {
            $source = $mail;
        } elseif ($company === 'Stanford Health Care') {
            $source = $userPrincipalName;
        } else {
            // Fallback: prefer mail, then UPN
            $source = $mail ?: $userPrincipalName;
        }

        $source = trim((string)($source ?? ''));
        if ($source === '') {
            return null;
        }

        $atPos = strpos($source, '@');
        if ($atPos === false) {
            return $source;
        }

        $username = substr($source, 0, $atPos);
        $username = trim((string)$username);
        return $username !== '' ? $username : null;
    }

    /**
     * Lazily-initialized GraphServiceClient instance.
     *
     * @var GraphServiceClient|null
     */
    private $client;

    private $tenantId;
    private $clientId;
    private $clientSecret;

    /**
     * Array of user attributes to include in `$select`.
     *
     * @var string[]
     */
    private $attributes;

    /**
     * Cached URL (string) for Stanford University default avatar.
     *
     * @var string|null
     */
    private $SUImage;

    /**
     * Cached URL (string) for Stanford Medicine default avatar.
     *
     * @var string|null
     */
    private $SoMImage;
    /**
     * Cached app-only Graph access token and its expiry (epoch seconds).
     *
     * Token is refreshed when within a safety window of 60 seconds before expiry.
     *
     * @var string|null $accessToken
     * @var int $accessTokenExpiresAt
     */
    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;
    /**
     * Default projection for user objects returned from Graph.
     * You may override by passing `$attributes` to the constructor.
     *
     * @var string[]
     */
    private const DEFAULT_ATTRIBUTES = [
        'id','displayName','givenName','surname','mail','userPrincipalName','accountEnabled',
        'jobTitle','department','companyName','officeLocation','businessPhones','mobilePhone',
        'preferredLanguage','identities','otherMails','mailNickname','usageLocation','createdDateTime',
        'assignedLicenses','assignedPlans','onPremisesExtensionAttributes','streetAddress','city','state',
        'postalCode','country','physicalDeliveryOfficeName','telephoneNumber','userType','showInAddressList'
    ];
    /**
     * Reference to the REDCap External Module instance for URL generation, etc.
     *
     * @var mixed
     */
    private $module;

    /**
     * @param mixed               $module        REDCap EM instance used to build asset URLs.
     * @param string[]            $attributes    Optional override for `$select` attributes.
     */
    public function __construct($tenantId, $clientId, $clientSecret, $module, $attributes = [])
    {
        $this->module = $module;
        $this->tenantId = $tenantId;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->attributes = $attributes && is_array($attributes) && count($attributes) > 0
            ? array_values(array_unique(array_map('trim', $attributes)))
            : self::DEFAULT_ATTRIBUTES;
    }

    /**
     * Initialize (once) and return the Microsoft Graph client using client credentials.
     *
     * Credentials are loaded from Google Secret Manager using keys:
     * MS_GRAPH_TENANT_ID, MS_GRAPH_CLIENT_ID, MS_GRAPH_CLIENT_SECRET.
     *
     * @return GraphServiceClient
     * @throws ApiException On errors from underlying SDK calls.
     */
    public function getGraphClient(): GraphServiceClient
    {
        if (!$this->client) {
            $tokenRequestContext = new ClientCredentialContext(
                $this->tenantId,
                $this->clientId,
                $this->clientSecret,
            );
            $this->client = new GraphServiceClient($tokenRequestContext);
        }
        return $this->client;
    }

    /**
     * Build UsersRequestBuilder query parameters with `$select`, `$expand`, and paging.
     *
     * @param string $filter OData filter expression.
     * @return UsersRequestBuilderGetQueryParameters
     */
    private function getQueryParams($filter)
    {
        return new UsersRequestBuilderGetQueryParameters(
            select: $this->attributes,
            filter: $filter,
            count: false,
            expand: ['manager($select=id,displayName,mail,userPrincipalName)'],
            top: 10
        );
    }

    /**
     * Build UsersRequestBuilder query parameters for advanced filters (e.g., companyName) without $expand.
     *
     * Advanced directory queries require $count=true and ConsistencyLevel: eventual.
     *
     * @param string $filter OData filter expression.
     * @return UsersRequestBuilderGetQueryParameters
     */
    private function getAdvancedQueryParams(?string $search, ?string $filter)
    {
        return new UsersRequestBuilderGetQueryParameters(
            select: $this->attributes,
            search: $search,
            filter: $filter,
            count: true,
            top: 10
        );
    }

    /**
     * Build a safe OData filter that searches across UPN, mailNickname, mail, givenName, and surname.
     *
     * Single quotes inside the search term are escaped per OData (`''`).
     *
     * @param string $searchTerm
     * @return string OData filter expression.
     */
    private function buildSearchFilter(string $searchTerm): string
    {
        // Build Graph $search query string across key properties.
        // Quotes inside the term are escaped for safety.
        $term = trim($searchTerm);
        // Escape double quotes since $search uses them as delimiters
        $escaped = str_replace('"', '\"', $term);

        // Use displayName, userPrincipalName, mail, mailNickname, givenName, surname
        return sprintf(
            "\"displayName:%s\" OR \"userPrincipalName:%s\" OR \"mail:%s\" OR \"mailNickname:%s\" OR \"givenName:%s\" OR \"surname:%s\"",
            $escaped,
            $escaped,
            $escaped,
            $escaped,
            $escaped,
            $escaped
        );
    }

    /**
     * Search users by term and return a normalized payload including `users`, `preview`, and paging links.
     *
     * @param string      $searchTerm Ignored. Method always returns enabled users only.
     * @param string|null $nextLink   Optional absolute `@odata.nextLink` for subsequent pages.
     * @param string|null $companyName Optional company name filter.
     * @return array{count:int|null, users:array, preview:array, nextLink:?string, prevLink:?string, @odata.nextLink:?string}
     * @throws ApiException
     * @throws \Exception
     * @throws \Throwable
     */
    public function searchUsers(string $searchTerm, $nextLink = null, $companyName = null): array
    {
        // Base $search expression on name/mail fields
        $search = $this->buildSearchFilter($searchTerm);

        // Single Graph $filter that OR-combines the 3 Stanford org conditions.
        // NOTE: Graph does not support contains() in $filter; domain checks use endswith(mail,'@domain').

        $adult = "(accountEnabled eq true"
            . " and not endswith(userPrincipalName,'-a@stanfordhealthcare.org')"
            . " and startswith(userPrincipalName,'S0')"
            . " and endswith(mail,'@stanfordhealthcare.org')"
            . " and userType ne 'Guest')";

        $children = "(accountEnabled eq true"
            . " and userType eq 'Guest'"
            . " and endswith(mail,'@stanfordchildrens.org'))";

        $university = "(accountEnabled eq true"
            . " and userType eq 'Guest'"
            . " and endswith(mail,'@stanford.edu'))";

        $companyFilter = $adult . ' or ' . $children . ' or ' . $university;

        return $this->getUsersByFilter($search, $nextLink, $companyFilter);
    }


    /**
     * Execute a users query given a Graph $search expression, optional nextLink for pagination, and optional companyName filter.
     *
     * Always uses advanced query params: $search, $filter (companyName), $count=true, ConsistencyLevel.
     * Manager information is fetched with separate calls per user (no $expand).
     *
     * @param string      $search        Graph $search expression.
     * @param string|null $nextLink      Absolute `@odata.nextLink` from a previous response (optional).
     * @param string|null $companyFilter Optional OData filter expression for companyName.
     * @return array{count:int|null, users:array, preview:array, nextLink:?string, prevLink:?string, @odata.nextLink:?string}
     */
    /**
     * Determine whether a user is a Stanford-affiliated user based on companyName or email/UPN.
     *
     * @param array $user Normalized user array.
     * @return bool
     */
    private function isStanfordUser(array $user): bool
    {

        // Fallback (backward compatibility): if companyName is missing/unreliable, use email domain checks.
        $emails = [];
        if (!empty($user['mail'])) {
            $emails[] = strtolower($user['mail']);
        }
        if (!empty($user['userPrincipalName'])) {
            $emails[] = strtolower($user['userPrincipalName']);
        }
        if (!empty($user['otherMails']) && is_array($user['otherMails'])) {
            foreach ($user['otherMails'] as $m) {
                if ($m) {
                    $emails[] = strtolower($m);
                }
            }
        }

        foreach ($emails as $email) {
            if (
                str_ends_with($email, '@stanford.edu') ||
                str_ends_with($email, '@stanfordhealthcare.org') ||
                str_ends_with($email, '@stanfordchildrens.org')
            ) {
                return true;
            }
        }
        return false;
    }

    public function getUsersByFilter($search, $nextLink, $companyFilter = null)
    {
        $graphClient = $this->getGraphClient();

        // Use advanced query params (no $expand, $count=true, ConsistencyLevel) for $search + optional company filter
        $useAdvanced = true;
        $queryParams = $this->getAdvancedQueryParams($search, $companyFilter);

        $requestConfig = new UsersRequestBuilderGetRequestConfiguration();
        $requestConfig->queryParameters = $queryParams;
        $requestConfig->headers = ['ConsistencyLevel' => 'eventual'];

        if (!is_null($nextLink)) {
            // Use a raw RequestInformation against the absolute nextLink URL.
            // This bypasses SDK query-parameter gaps (e.g., missing skiptoken property).
            $requestInfo = new RequestInformation();
            $requestInfo->httpMethod = HttpMethod::GET;
            $requestInfo->urlTemplate = $nextLink;  // absolute URL
            $requestInfo->pathParameters = [];
            $headers = ['Accept' => 'application/json'];
            if ($useAdvanced) {
                $headers['ConsistencyLevel'] = 'eventual';
            }
            $requestInfo->addHeaders($headers);

            // Send and parse into a UserCollectionResponse
            $response = $graphClient->getRequestAdapter()
                ->sendAsync($requestInfo, [UserCollectionResponse::class, 'createFromDiscriminatorValue'])
                ->wait();
        } else {
            // First page
            $response = $graphClient->users()->get($requestConfig)->wait();
        }

        $image = $this->module->getUrl('ajax/get_user_photo.php', true, true);
        $managerURL = $this->module->getUrl('ajax/get_user_manager.php', true, true);
        $users = [];
        foreach ($response->getValue() as $user) {
            // Collections / complex types with safe normalization
            $businessPhones = $user->getBusinessPhones() ?: [];

            $identities = [];
            if (method_exists($user, 'getIdentities') && $user->getIdentities()) {
                foreach ($user->getIdentities() as $idn) {
                    $identities[] = [
                        'signInType' => method_exists($idn, 'getSignInType') ? $idn->getSignInType() : null,
                        'issuer' => method_exists($idn, 'getIssuer') ? $idn->getIssuer() : null,
                        'issuerAssignedId' => method_exists($idn, 'getIssuerAssignedId') ? $idn->getIssuerAssignedId() : null,
                    ];
                }
            }

            $assignedLicenses = [];
            if (method_exists($user, 'getAssignedLicenses') && $user->getAssignedLicenses()) {
                foreach ($user->getAssignedLicenses() as $lic) {
                    $assignedLicenses[] = [
                        'skuId' => method_exists($lic, 'getSkuId') ? (string)$lic->getSkuId() : null,
                        'disabledPlans' => method_exists($lic, 'getDisabledPlans') ? array_map('strval', $lic->getDisabledPlans()) : [],
                    ];
                }
            }

            $assignedPlans = [];
            if (method_exists($user, 'getAssignedPlans') && $user->getAssignedPlans()) {
                foreach ($user->getAssignedPlans() as $pl) {
                    $assignedPlans[] = [
                        'service' => method_exists($pl, 'getService') ? $pl->getService() : null,
                        'servicePlanId' => method_exists($pl, 'getServicePlanId') ? (string)$pl->getServicePlanId() : null,
                        'capabilityStatus' => method_exists($pl, 'getCapabilityStatus') ? $pl->getCapabilityStatus() : null,
                        'assignedDateTime' => method_exists($pl, 'getAssignedDateTime') && $pl->getAssignedDateTime() ? $pl->getAssignedDateTime()->format(DATE_ATOM) : null,
                    ];
                }
            }

            $onPremExt = null;
            if (method_exists($user, 'getOnPremisesExtensionAttributes') && $user->getOnPremisesExtensionAttributes()) {
                $ext = $user->getOnPremisesExtensionAttributes();
                $onPremExt = [];
                for ($i=1; $i<=15; $i++) {
                    $getter = 'getExtensionAttribute'.$i;
                    $onPremExt['extensionAttribute'.$i] = method_exists($ext, $getter) ? $ext->$getter() : null;
                }
            }

            // Manager (from $expand) — best effort across SDK versions
            // Manager
            $manager = null;
            if (!$useAdvanced) {
                // When not using advanced filter, get manager via $expand (if available)
                if (method_exists($user, 'getManager') && $user->getManager()) {
                    $mgr = $user->getManager();
                    $manager = [
                        'id' => method_exists($mgr, 'getId') ? $mgr->getId() : null,
                        'displayName' => method_exists($mgr, 'getDisplayName') ? $mgr->getDisplayName() : null,
                        'mail' => method_exists($mgr, 'getMail') ? $mgr->getMail() : null,
                        'userPrincipalName' => method_exists($mgr, 'getUserPrincipalName') ? $mgr->getUserPrincipalName() : null,
                    ];
                } elseif (method_exists($user, 'getAdditionalData')) {
                    $ad = $user->getAdditionalData();
                    if (isset($ad['manager']) && is_array($ad['manager'])) {
                        $manager = [
                            'id' => $ad['manager']['id'] ?? null,
                            'displayName' => $ad['manager']['displayName'] ?? null,
                            'mail' => $ad['manager']['mail'] ?? null,
                            'userPrincipalName' => $ad['manager']['userPrincipalName'] ?? null,
                        ];
                    }
                }
            }

            // Additional raw fields (appear via additionalData if not strongly typed)
            $principal = null;
            $alternativeSecurityIds = null;
            $isSoftDeleted = null;
            if (method_exists($user, 'getAdditionalData')) {
                $ad = $user->getAdditionalData();
                $principal = $ad['principal'] ?? null;
                $alternativeSecurityIds = $ad['alternativeSecurityIds'] ?? null;
                $isSoftDeleted = $ad['IsSoftDeleted'] ?? ($ad['isSoftDeleted'] ?? null);
            }

            $companyNameVal = $user->getCompanyName();
            $mailVal = $user->getMail();
            $upnVal = $user->getUserPrincipalName();
            $effectiveMailNickname = $this->computeMailNickname($companyNameVal, $mailVal, $upnVal);

            $normalizedUser = [
                'id' => $user->getId(),
                'displayName' => $user->getDisplayName(),
                'givenName' => $user->getGivenName(),
                'surname' => $user->getSurname(),
                'mail' => $user->getMail(),
                'userPrincipalName' => $user->getUserPrincipalName(),
                'accountEnabled' => $user->getAccountEnabled(),
                'jobTitle' => $user->getJobTitle(),
                'department' => $user->getDepartment(),
                'companyName' => $user->getCompanyName(),
                'officeLocation' => $user->getOfficeLocation(),
                'businessPhones' => $businessPhones,
                'mobilePhone' => $user->getMobilePhone(),
                'preferredLanguage' => $user->getPreferredLanguage(),
                'identities' => $identities,
                'otherMails' => method_exists($user, 'getOtherMails') && $user->getOtherMails() ? $user->getOtherMails() : [],
                'mailNickname' => $effectiveMailNickname,
                'usageLocation' => $user->getUsageLocation(),
                'createdDateTime' => $user->getCreatedDateTime() ? $user->getCreatedDateTime()->format(DATE_ATOM) : null,
                'assignedLicenses' => $assignedLicenses,
                'assignedPlans' => $assignedPlans,
                'onPremisesExtensionAttributes' => $onPremExt,
                'streetAddress' => $user->getStreetAddress(),
                'city' => $user->getCity(),
                'state' => $user->getState(),
                'postalCode' => $user->getPostalCode(),
                'country' => $user->getCountry(),
                'physicalDeliveryOfficeName' => method_exists($user, 'getPhysicalDeliveryOfficeName') ? $user->getPhysicalDeliveryOfficeName() : null,
                'telephoneNumber' => method_exists($user, 'getTelephoneNumber') ? $user->getTelephoneNumber() : null,
                'userType' => $user->getUserType(),
                'showInAddressList' => method_exists($user, 'getShowInAddressList') ? $user->getShowInAddressList() : null,
                'manager' => $manager,
                'principal' => $principal,
                'alternativeSecurityIds' => $alternativeSecurityIds,
                'IsSoftDeleted' => $isSoftDeleted,
                'photoUrl' => $image . '&user_id=' . urlencode($user->getId()) . '&size=120x120',
                'managerURL' => $managerURL . '&user_id=' . urlencode($user->getId()),
                // backward compatibility with OneDirectory fields
                'OneDirectoryId' => $user->getId(),
                'affiliate' => $user->getCompanyName(),
                'jobId' => '',
                'first_name' => $user->getGivenName(),
                'last_name' => $user->getSurname(),
                'fullname' => $user->getDisplayName(),
                'phone' => $user->getMobilePhone() ?: (isset($businessPhones[0]) ? $businessPhones[0] : null),
                'email' => $user->getMail(),
                'title' => $user->getJobTitle(),
                'suid' => $effectiveMailNickname,
            ];

            // Skip non-Stanford users
            if (!$this->isStanfordUser($normalizedUser)) {
                continue;
            }

            $users[] = $normalizedUser;
        }

        // For advanced companyName queries, fetch manager info per user in a second pass
//        if ($useAdvanced && !empty($users)) {
//            $users = $this->attachManagers($users);
//        }

        $prevLinkVar = $nextLink ?: null;              // the link the client just used (if any)
        $nextLinkVar = $response->getOdataNextLink();  // the link for the next page (from Graph)
        return [
            'count' => $response->getOdataCount(),
            'users' => $users,
            'preview' => $this->createUserPreview($users),
            'nextLink' => $nextLinkVar,
            'prevLink' => $prevLinkVar,
            '@odata.nextLink' => $nextLinkVar,
        ];
    }


    /**
     * Populate the `manager` field for each user by calling the `/users/{id}/manager` endpoint.
     *
     * Manager calls use the async Graph SDK API under the hood; we wait on each promise so
     * the calling code receives a fully-populated array.
     *
     * @param array $users Normalized users from getUsersByFilter().
     * @return array Users with `manager` populated when available.
     */
    private function attachManagers(array $users): array
    {
        if (empty($users)) {
            return $users;
        }

        $graphClient = $this->getGraphClient();

        foreach ($users as &$user) {
            $manager = null;
            try {
                // Kiota SDK returns a promise; we call wait() to resolve it.
                $mgrObj = $graphClient->users()->byUserId($user['id'])->manager()->get()->wait();
                if ($mgrObj) {
                    $manager = [
                        'id' => method_exists($mgrObj, 'getId') ? $mgrObj->getId() : null,
                        'displayName' => method_exists($mgrObj, 'getDisplayName') ? $mgrObj->getDisplayName() : null,
                        'mail' => method_exists($mgrObj, 'getMail') ? $mgrObj->getMail() : null,
                        'userPrincipalName' => method_exists($mgrObj, 'getUserPrincipalName') ? $mgrObj->getUserPrincipalName() : null,
                    ];
                }
            } catch (\Throwable $e) {
                // Swallow manager lookup errors; leave manager as null
                $manager = null;
            }
            $user['manager'] = $manager;
        }
        unset($user);

        return $users;
    }
    /**
     * Create compact preview cards array for the UI, picking the correct default image.
     *
     * If a user does not have a profile photo, defaults to Stanford University or
     * Stanford Medicine image based on companyName.
     *
     * @param array $users Normalized users array from getUsersByFilter().
     * @return array
     */
    private function createUserPreview($users)
    {

        $result = array();
        if (!empty($users)) {
            foreach ($users as $user) {
                if ($user['companyName'] == "Stanford University") {
                    $image = $this->getSUImage();
                } else {
                    $image = $this->getSoMImage();
                }

                // Use profile photo if available, otherwise fallback to URL image (now base64)
                $finalImage = !empty($user['photoUrl'])
                    ? $user['photoUrl']
                    : $image;

                $result[] = array(
                    'id'    => $user['id'],
                    'label'    => $user['displayName'],
                    'title'    => $user['jobTitle'],
                    'suid'    => $user['mailNickname'],
                    'value'    => $user['displayName'],
                    'array' => $user,
                    'image' => $finalImage
                );
            }
        }
        return $result;
    }

    /**
     * Get (and cache) the Stanford University default avatar URL.
     *
     * @return string
     */
    private function getSUImage()
    {
        if(!$this->SUImage) {
            $this->SUImage = $this->module->getUrl('assets/images/stanford_university.png', true, true);
        }
        return $this->SUImage;
    }

    /**
     * Get (and cache) the Stanford Medicine default avatar URL.
     *
     * @return string
     */
    private function getSoMImage()
    {
        if(!$this->SoMImage) {
            $this->SoMImage = $this->module->getUrl('assets/images/stanford_medicine.png', true, true);

        }
        return $this->SoMImage;
    }
    /**
     * Acquire and cache an app-only access token for Microsoft Graph via OAuth2 client credentials.
     *
     * The token is fetched from `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
     * with scope `https://graph.microsoft.com/.default`, cached in-memory, and reused until
     * 60 seconds before expiration.
     *
     * @return string|null Bearer access token, or null if retrieval fails.
     */
    public function getAccessToken(): ?string
    {
        $now = time();
        if ($this->accessToken && ($this->accessTokenExpiresAt - 60) > $now) {
            return $this->accessToken;
        }

        // Load credentials from Secret Manager
        $tenantId = trim($this->tenantId);
        $clientId = trim($this->clientId);
        $clientSecret = $this->clientSecret;
        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            error_log('[MSGraphClient] Missing Graph app credentials (TENANT/CLIENT_ID/CLIENT_SECRET).');
            return null;
        }

        $authorityHost = 'https://login.microsoftonline.com';
        $tokenEndpoint = $authorityHost . '/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';

        $http = new GuzzleClient([
            'timeout' => 5.0,
            'connect_timeout' => 2.0,
        ]);
        try {
            $resp = $http->post($tokenEndpoint, [
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            $status = $resp->getStatusCode();
            $body = (string) $resp->getBody();
            if ($status !== 200) {
                error_log('[MSGraphClient] Token request failed: HTTP ' . $status . ' body=' . substr($body, 0, 500));
                return null;
            }
            $json = json_decode($body, true);
            if (!is_array($json) || empty($json['access_token'])) {
                error_log('[MSGraphClient] Token response missing access_token: ' . substr($body, 0, 500));
                return null;
            }
            $this->accessToken = $json['access_token'];
            $expiresIn = isset($json['expires_in']) ? (int)$json['expires_in'] : 3600;
            $this->accessTokenExpiresAt = $now + max(300, $expiresIn);
            return $this->accessToken;
        } catch (GuzzleException $e) {
            error_log('[MSGraphClient] Token request error: ' . $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            error_log('[MSGraphClient] Token request unexpected error: ' . $e->getMessage());
            return null;
        }
    }
}
