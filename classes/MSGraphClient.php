<?php

namespace Stanford\RedcapOneDirectoryLookup;


use Google\ApiCore\ApiException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

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
    const MS_GRAPH_CLIENT_ID = 'MS_GRAPH_CLIENT_ID';
    const MS_GRAPH_TENANT_ID = 'MS_GRAPH_TENANT_ID';
    const MS_GRAPH_CLIENT_SECRET = 'MS_GRAPH_CLIENT_SECRET';
    private $companyNameMap = [
        '1' => "Stanford Children's Health",
        '2' => 'Stanford Health Care',
        '3' => 'Stanford University',
    ];

    /**
     * Ignore-list for non-real / service / test accounts.
     * If the FIRST or LAST name is exactly one of these words (case-insensitive), the user is skipped.
     *
     * Keep this list small and conservative.
     *
     * @var string[]
     */
    private array $ignoreNameWords = [
        'test',
        'admin',
    ];

    /**
     * Ignore-list for non-real / service / test accounts by email.
     *
     * If any candidate email (mail / userPrincipalName / otherMails) matches one of these words
     * (case-insensitive) either as the full address or the local-part (before '@'), the user is skipped.
     *
     * Keep this list small and conservative.
     *
     * @var string[]
     */
    private array $ignoreEmailWords = [
        'testjamf2',
        'test365',
        "admin-hospitalmed",
        "qle_admins"
    ];
    private GoogleSecretManager $secretManager;


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
        'id', 'displayName', 'givenName', 'surname', 'mail', 'userPrincipalName', 'accountEnabled',
        'jobTitle', 'department', 'companyName', 'officeLocation', 'businessPhones', 'mobilePhone',
        'preferredLanguage', 'identities', 'otherMails', 'mailNickname', 'usageLocation', 'createdDateTime',
        'assignedLicenses', 'assignedPlans', 'onPremisesExtensionAttributes', 'streetAddress', 'city', 'state',
        'postalCode', 'country', 'physicalDeliveryOfficeName', 'telephoneNumber', 'userType', 'showInAddressList'
    ];
    /**
     * Reference to the REDCap External Module instance for URL generation, etc.
     *
     * @var mixed
     */
    private $module;

    /**
     * @param mixed $module REDCap EM instance used to build asset URLs.
     * @param string[] $attributes Optional override for `$select` attributes.
     */
    public function __construct(GoogleSecretManager $secretManager, $module,$attributes = [])
    {
        $this->module = $module;
        $this->secretManager = $secretManager;
        $this->attributes = $attributes && is_array($attributes) && count($attributes) > 0
            ? array_values(array_unique(array_map('trim', $attributes)))
            : self::DEFAULT_ATTRIBUTES;
    }

    /**
     * Make a GET request to Microsoft Graph API with the cached access token.
     *
     * @param string $endpoint The API endpoint (e.g., '/users')
     * @param array $queryParams Query parameters to append to the URL
     * @param array $additionalHeaders Additional headers to include in the request
     * @return array Decoded JSON response, or empty array on error
     */
    private function graphGetRequest(string $endpoint, array $queryParams = [], array $additionalHeaders = []): array
    {
        error_log('[MSGraphClient] graphGetRequest() called for endpoint: ' . $endpoint);

        // Get (or refresh) the access token from system settings
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            error_log('[MSGraphClient] Failed to get access token for Graph request.');
            return [];
        }
        error_log('[MSGraphClient] Access token obtained (length: ' . strlen($accessToken) . ' bytes)');

        $baseUrl = 'https://graph.microsoft.com/v1.0';
        $url = $baseUrl . $endpoint;

        if (!empty($queryParams)) {
            $queryString = http_build_query($queryParams);
            $url .= '?' . $queryString;
            error_log('[MSGraphClient] Query parameters: ' . $queryString);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // Merge additional headers
        $headers = array_merge($headers, $additionalHeaders);
        if (!empty($additionalHeaders)) {
            error_log('[MSGraphClient] Additional headers: ' . implode(', ', array_keys($additionalHeaders)));
        }

        error_log('[MSGraphClient] Full URL: ' . $url);

        $http = new GuzzleClient([
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
        ]);

        try {
            error_log('[MSGraphClient] Sending GET request to Graph...');
            $response = $http->get($url, ['headers' => $headers]);

            $statusCode = $response->getStatusCode();
            error_log('[MSGraphClient] Graph response received with status: ' . $statusCode);

            if ($statusCode !== 200) {
                error_log('[MSGraphClient] ERROR: Graph request failed with HTTP ' . $statusCode);
                return [];
            }

            $body = (string)$response->getBody();
            error_log('[MSGraphClient] Response body length: ' . strlen($body) . ' bytes');

            $data = json_decode($body, true);
            if (!is_array($data)) {
                error_log('[MSGraphClient] ERROR: Failed to parse Graph response as JSON');
                return [];
            }

            $valueCount = isset($data['value']) && is_array($data['value']) ? count($data['value']) : 0;
            error_log('[MSGraphClient] Graph response parsed. Contains ' . $valueCount . ' items');

            return $data;
        } catch (GuzzleException $e) {
            error_log('[MSGraphClient] GuzzleException during Graph request: ' . $e->getMessage());
            error_log('[MSGraphClient] Request URI: ' . $e->getRequest()->getUri());
            if ($e->hasResponse()) {
                error_log('[MSGraphClient] Response status: ' . $e->getResponse()->getStatusCode());
                error_log('[MSGraphClient] Response body (first 500 chars): ' . substr((string)$e->getResponse()->getBody(), 0, 500));
            }
            return [];
        } catch (\Throwable $e) {
            error_log('[MSGraphClient] Unexpected error in Graph request: ' . $e->getMessage());
            error_log('[MSGraphClient] Exception class: ' . get_class($e));
            return [];
        }
    }

    /**
     * Fetch a page from Microsoft Graph using an absolute URL (nextLink).
     *
     * @param string $url Absolute URL from @odata.nextLink
     * @return array Decoded JSON response, or empty array on error
     */
    private function fetchGraphPageByUrl(string $url): array
    {
        error_log('[MSGraphClient] fetchGraphPageByUrl() called');
        error_log('[MSGraphClient] Pagination URL: ' . $url);

        // Get (or refresh) the access token from system settings
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            error_log('[MSGraphClient] Failed to get access token for pagination request.');
            return [];
        }
        error_log('[MSGraphClient] Access token obtained for pagination (length: ' . strlen($accessToken) . ' bytes)');

        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'ConsistencyLevel' => 'eventual',
        ];

        $http = new GuzzleClient([
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
        ]);

        try {
            error_log('[MSGraphClient] Sending pagination request to Graph...');
            $response = $http->get($url, ['headers' => $headers]);

            $statusCode = $response->getStatusCode();
            error_log('[MSGraphClient] Pagination response received with status: ' . $statusCode);

            if ($statusCode !== 200) {
                error_log('[MSGraphClient] ERROR: Pagination request failed with HTTP ' . $statusCode);
                return [];
            }

            $body = (string)$response->getBody();
            error_log('[MSGraphClient] Response body length: ' . strlen($body) . ' bytes');

            $data = json_decode($body, true);
            if (!is_array($data)) {
                error_log('[MSGraphClient] ERROR: Failed to parse pagination response as JSON');
                return [];
            }

            $valueCount = isset($data['value']) && is_array($data['value']) ? count($data['value']) : 0;
            error_log('[MSGraphClient] Pagination response parsed. Contains ' . $valueCount . ' items');

            if (isset($data['@odata.nextLink'])) {
                error_log('[MSGraphClient] Next page available via @odata.nextLink');
            } else {
                error_log('[MSGraphClient] No more pages (@odata.nextLink not present)');
            }

            return $data;
        } catch (GuzzleException $e) {
            error_log('[MSGraphClient] GuzzleException during pagination request: ' . $e->getMessage());
            error_log('[MSGraphClient] Request URI: ' . $e->getRequest()->getUri());
            if ($e->hasResponse()) {
                error_log('[MSGraphClient] Response status: ' . $e->getResponse()->getStatusCode());
                error_log('[MSGraphClient] Response body (first 500 chars): ' . substr((string)$e->getResponse()->getBody(), 0, 500));
            }
            return [];
        } catch (\Throwable $e) {
            error_log('[MSGraphClient] Unexpected error in pagination request: ' . $e->getMessage());
            error_log('[MSGraphClient] Exception class: ' . get_class($e));
            return [];
        }
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
     * @param string $searchTerm Ignored. Method always returns enabled users only.
     * @param string|null $nextLink Optional absolute `@odata.nextLink` for subsequent pages.
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

        // If companyName is defined, use it as a filter; otherwise use the default Stanford org conditions
        if (!empty($companyName)) {
            $companyFilter = "companyName eq '" . str_replace("'", "''", $this->companyNameMap[$companyName]) . "'";
        } else {
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
        }

        return $this->getUsersByFilter($search, $nextLink, $companyFilter);
    }


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
     * Execute a users query given a Graph $search expression, optional nextLink for pagination, and optional companyName filter.
     *
     * Always uses advanced query params: $search, $filter (companyName), $count=true, ConsistencyLevel.
     * Manager information is fetched with separate calls per user (no $expand).
     *
     * @param string $search Graph $search expression.
     * @param string|null $nextLink Absolute `@odata.nextLink` from a previous response (optional).
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
        $response = null;

        if (!is_null($nextLink)) {
            // Use the nextLink directly - it contains all necessary query params
            $response = $this->fetchGraphPageByUrl($nextLink);
        } else {
            // First page - build OData query params
            $queryParams = [
                '$search' => $search,
                '$count' => 'true',
                '$top' => '10',
            ];

            if (!empty($companyFilter)) {
                $queryParams['$filter'] = $companyFilter;
            }

            $queryParams['$select'] = implode(',', $this->attributes);

            $response = $this->graphGetRequest('/users', $queryParams, ['ConsistencyLevel' => 'eventual']);
        }

        if (empty($response)) {
            return [
                'count' => null,
                'users' => [],
                'preview' => [],
                'nextLink' => null,
                'prevLink' => null,
                '@odata.nextLink' => null,
            ];
        }

        $image = $this->module->getUrl('ajax/get_user_photo.php', true, true);
        $managerURL = $this->module->getUrl('ajax/get_user_manager.php', true, true);
        $users = [];

        // Get users array from response
        $usersList = $response['value'] ?? [];
        foreach ($usersList as $user) {
            // Collections / complex types with safe normalization
            $businessPhones = $user['businessPhones'] ?? [];

            // identities array
            $identities = [];
            if (isset($user['identities']) && is_array($user['identities'])) {
                foreach ($user['identities'] as $idn) {
                    $identities[] = [
                        'signInType' => $idn['signInType'] ?? null,
                        'issuer' => $idn['issuer'] ?? null,
                        'issuerAssignedId' => $idn['issuerAssignedId'] ?? null,
                    ];
                }
            }

            // assignedLicenses array
            $assignedLicenses = [];
            if (isset($user['assignedLicenses']) && is_array($user['assignedLicenses'])) {
                foreach ($user['assignedLicenses'] as $lic) {
                    $assignedLicenses[] = [
                        'skuId' => $lic['skuId'] ?? null,
                        'disabledPlans' => $lic['disabledPlans'] ?? [],
                    ];
                }
            }

            // assignedPlans array
            $assignedPlans = [];
            if (isset($user['assignedPlans']) && is_array($user['assignedPlans'])) {
                foreach ($user['assignedPlans'] as $pl) {
                    $assignedPlans[] = [
                        'service' => $pl['service'] ?? null,
                        'servicePlanId' => $pl['servicePlanId'] ?? null,
                        'capabilityStatus' => $pl['capabilityStatus'] ?? null,
                        'assignedDateTime' => $pl['assignedDateTime'] ?? null,
                    ];
                }
            }

            // onPremisesExtensionAttributes
            $onPremExt = null;
            if (isset($user['onPremisesExtensionAttributes']) && is_array($user['onPremisesExtensionAttributes'])) {
                $onPremExt = $user['onPremisesExtensionAttributes'];
            }

            // Manager (from $expand)
            $manager = $user['manager'] ?? null;

            // Additional fields
            $principal = $user['principal'] ?? null;
            $alternativeSecurityIds = $user['alternativeSecurityIds'] ?? null;
            $isSoftDeleted = $user['IsSoftDeleted'] ?? ($user['isSoftDeleted'] ?? null);

            $companyNameVal = $user['companyName'] ?? null;
            $mailVal = $user['mail'] ?? null;
            $upnVal = $user['userPrincipalName'] ?? null;
            $effectiveMailNickname = $this->computeMailNickname($companyNameVal, $mailVal, $upnVal);

            $normalizedUser = [
                'id' => $user['id'] ?? null,
                'displayName' => $user['displayName'] ?? null,
                'givenName' => $user['givenName'] ?? null,
                'surname' => $user['surname'] ?? null,
                'mail' => $user['mail'] ?? null,
                'userPrincipalName' => $user['userPrincipalName'] ?? null,
                'accountEnabled' => $user['accountEnabled'] ?? null,
                'jobTitle' => $user['jobTitle'] ?? null,
                'department' => $user['department'] ?? null,
                'companyName' => $user['companyName'] ?? null,
                'officeLocation' => $user['officeLocation'] ?? null,
                'businessPhones' => $businessPhones,
                'mobilePhone' => $user['mobilePhone'] ?? null,
                'preferredLanguage' => $user['preferredLanguage'] ?? null,
                'identities' => $identities,
                'otherMails' => $user['otherMails'] ?? [],
                'mailNickname' => $effectiveMailNickname,
                'usageLocation' => $user['usageLocation'] ?? null,
                'createdDateTime' => $user['createdDateTime'] ?? null,
                'assignedLicenses' => $assignedLicenses,
                'assignedPlans' => $assignedPlans,
                'onPremisesExtensionAttributes' => $onPremExt,
                'streetAddress' => $user['streetAddress'] ?? null,
                'city' => $user['city'] ?? null,
                'state' => $user['state'] ?? null,
                'postalCode' => $user['postalCode'] ?? null,
                'country' => $user['country'] ?? null,
                'physicalDeliveryOfficeName' => $user['physicalDeliveryOfficeName'] ?? null,
                'telephoneNumber' => $user['telephoneNumber'] ?? null,
                'userType' => $user['userType'] ?? null,
                'showInAddressList' => $user['showInAddressList'] ?? null,
                'manager' => $manager,
                'principal' => $principal,
                'alternativeSecurityIds' => $alternativeSecurityIds,
                'IsSoftDeleted' => $isSoftDeleted,
                'photoUrl' => $image . '&user_id=' . urlencode($user['id'] ?? '') . '&size=120x120',
                'managerURL' => $managerURL . '&user_id=' . urlencode($user['id'] ?? ''),
                // backward compatibility with OneDirectory fields
                'OneDirectoryId' => $user['id'] ?? null,
                'affiliate' => $user['companyName'] ?? null,
                'jobId' => '',
                'first_name' => $user['givenName'] ?? null,
                'last_name' => $user['surname'] ?? null,
                'fullname' => $user['displayName'] ?? null,
                'phone' => $user['mobilePhone'] ?? (isset($businessPhones[0]) ? $businessPhones[0] : null),
                'email' => $user['mail'] ?? null,
                'title' => $user['jobTitle'] ?? null,
                'suid' => $effectiveMailNickname,
            ];

            // Skip obvious non-real/service/test accounts
            if ($this->shouldIgnoreByName($normalizedUser) || $this->shouldIgnoreByEmail($normalizedUser)) {
                continue;
            }

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

        $prevLinkVar = $nextLink ?: null;
        $nextLinkVar = $response['@odata.nextLink'] ?? null;
        return [
            'count' => $response['@odata.count'] ?? null,
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
     * @param array $users Normalized users from getUsersByFilter().
     * @return array Users with `manager` populated when available.
     */
    private function attachManagers(array $users): array
    {
        if (empty($users)) {
            return $users;
        }

        foreach ($users as &$user) {
            $manager = null;
            try {
                if (empty($user['id'])) {
                    continue;
                }

                // Fetch manager info directly from Graph API
                $managerData = $this->graphGetRequest('/users/' . urlencode($user['id']) . '/manager', [
                    '$select' => 'id,displayName,mail,userPrincipalName'
                ]);

                if (!empty($managerData)) {
                    $manager = [
                        'id' => $managerData['id'] ?? null,
                        'displayName' => $managerData['displayName'] ?? null,
                        'mail' => $managerData['mail'] ?? null,
                        'userPrincipalName' => $managerData['userPrincipalName'] ?? null,
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
                    'id' => $user['id'],
                    'label' => $user['displayName'],
                    'title' => $user['jobTitle'],
                    'suid' => $user['mailNickname'],
                    'value' => $user['displayName'],
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
        if (!$this->SUImage) {
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
        if (!$this->SoMImage) {
            $this->SoMImage = $this->module->getUrl('assets/images/stanford_medicine.png', true, true);

        }
        return $this->SoMImage;
    }

    /**
     * Load credentials from Google Secret Manager on demand.
     * Credentials are loaded only once and cached in instance variables.
     *
     * @return bool True if credentials were loaded successfully, false otherwise.
     */
    private function loadCredentialsIfNeeded(): bool
    {
        // Only load if not already loaded
        if ($this->tenantId !== null && $this->clientId !== null && $this->clientSecret !== null) {
            error_log('[MSGraphClient] Credentials already loaded (cached in memory)');
            return true;
        }

        error_log('[MSGraphClient] Loading credentials from Google Secret Manager...');
        try {
            error_log('[MSGraphClient] Fetching MS_GRAPH_TENANT_ID secret...');
            $this->tenantId = $this->secretManager->getSecret(self::MS_GRAPH_TENANT_ID);
            error_log('[MSGraphClient] Successfully loaded MS_GRAPH_TENANT_ID');

            error_log('[MSGraphClient] Fetching MS_GRAPH_CLIENT_ID secret...');
            $this->clientId = $this->secretManager->getSecret(self::MS_GRAPH_CLIENT_ID);
            error_log('[MSGraphClient] Successfully loaded MS_GRAPH_CLIENT_ID');

            error_log('[MSGraphClient] Fetching MS_GRAPH_CLIENT_SECRET secret...');
            $this->clientSecret = $this->secretManager->getSecret(self::MS_GRAPH_CLIENT_SECRET);
            error_log('[MSGraphClient] Successfully loaded MS_GRAPH_CLIENT_SECRET');

            error_log('[MSGraphClient] All credentials loaded successfully');
            return true;
        } catch (ApiException $e) {
            error_log('[MSGraphClient] ApiException loading credentials: ' . $e->getMessage());
            error_log('[MSGraphClient] Status code: ' . $e->getCode());
            error_log('[MSGraphClient] Details: ' . $e->getDetails());
            return false;
        } catch (\Throwable $e) {
            error_log('[MSGraphClient] Unexpected error loading credentials: ' . $e->getMessage());
            error_log('[MSGraphClient] Exception class: ' . get_class($e));
            error_log('[MSGraphClient] Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Acquire and cache an app-only access token for Microsoft Graph via OAuth2 client credentials.
     *
     * The token is fetched from `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
     * with scope `https://graph.microsoft.com/.default`, cached in system settings, and reused until
     * 60 seconds before expiration.
     *
     * @return string|null Bearer access token, or null if retrieval fails.
     */
    public function getAccessToken(): ?string
    {
        $now = time();
        error_log('[MSGraphClient] getAccessToken() called at timestamp: ' . $now);

        // Check for cached token in system settings
        error_log('[MSGraphClient] Checking for cached token in system settings...');
        $cachedToken = $this->module->getSystemSetting('microsoft-graph-access-token');
        $tokenExpirationTs = $this->module->getSystemSetting('microsoft-graph-access-token-expiration-timestamp');

        // If token exists and is not expired (with 60-second safety window), return it
        if ($cachedToken && $tokenExpirationTs) {
            $tokenExpirationTs = (int)$tokenExpirationTs;
            $secondsUntilExpiry = $tokenExpirationTs - $now;
            $safetyWindowSeconds = 60;

            error_log('[MSGraphClient] Found cached token. Expires at: ' . $tokenExpirationTs . ', expires in: ' . $secondsUntilExpiry . ' seconds');

            if (($tokenExpirationTs - $safetyWindowSeconds) > $now) {
                error_log('[MSGraphClient] Cached token is valid (safety window check passed). Returning cached token.');
                return $cachedToken;
            } else {
                error_log('[MSGraphClient] Cached token is expired or within safety window. Need to refresh.');
            }
        } else {
            error_log('[MSGraphClient] No cached token found. Need to fetch new token.');
            if (!$cachedToken) {
                error_log('[MSGraphClient]   - Cached token is missing');
            }
            if (!$tokenExpirationTs) {
                error_log('[MSGraphClient]   - Token expiration timestamp is missing');
            }
        }

        // Load credentials from Google Secret Manager only when needed
        error_log('[MSGraphClient] Loading credentials for token request...');
        if (!$this->loadCredentialsIfNeeded()) {
            error_log('[MSGraphClient] Failed to load credentials. Cannot proceed with token request.');
            return null;
        }

        $tenantId = trim($this->tenantId);
        $clientId = trim($this->clientId);
        $clientSecret = $this->clientSecret;

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            error_log('[MSGraphClient] Missing Graph app credentials (TENANT/CLIENT_ID/CLIENT_SECRET).');
            error_log('[MSGraphClient]   - TenantId empty: ' . ($tenantId === '' ? 'YES' : 'NO'));
            error_log('[MSGraphClient]   - ClientId empty: ' . ($clientId === '' ? 'YES' : 'NO'));
            error_log('[MSGraphClient]   - ClientSecret empty: ' . ($clientSecret === '' ? 'YES' : 'NO'));
            return null;
        }

        $authorityHost = 'https://login.microsoftonline.com';
        $tokenEndpoint = $authorityHost . '/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
        error_log('[MSGraphClient] Token endpoint: ' . $tokenEndpoint);

        $http = new GuzzleClient([
            'timeout' => 5.0,
            'connect_timeout' => 2.0,
        ]);

        try {
            error_log('[MSGraphClient] Sending token request to Microsoft....');
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
            error_log('[MSGraphClient] Token request completed with HTTP status: ' . $status);

            $body = (string)$resp->getBody();
            if ($status !== 200) {
                error_log('[MSGraphClient] ERROR: Token request failed with HTTP ' . $status);
                error_log('[MSGraphClient] Response body (first 500 chars): ' . substr($body, 0, 500));
                return null;
            }

            $json = json_decode($body, true);
            if (!is_array($json)) {
                error_log('[MSGraphClient] ERROR: Failed to parse token response as JSON');
                error_log('[MSGraphClient] Response body: ' . substr($body, 0, 500));
                return null;
            }

            if (empty($json['access_token'])) {
                error_log('[MSGraphClient] ERROR: Token response missing access_token field');
                error_log('[MSGraphClient] Response keys: ' . implode(', ', array_keys($json)));
                error_log('[MSGraphClient] Response body: ' . substr($body, 0, 500));
                return null;
            }

            $token = $json['access_token'];
            $expiresIn = isset($json['expires_in']) ? (int)$json['expires_in'] : 3600;
            $expirationTs = $now + max(300, $expiresIn);

            error_log('[MSGraphClient] New token acquired. Token length: ' . strlen($token) . ' bytes');
            error_log('[MSGraphClient] Token expires in: ' . $expiresIn . ' seconds, will expire at: ' . $expirationTs);

            // Save token and expiration to system settings
            error_log('[MSGraphClient] Saving token to system settings...');
            $this->module->setSystemSetting('microsoft-graph-access-token', $token);
            $this->module->setSystemSetting('microsoft-graph-access-token-expiration-timestamp', (string)$expirationTs);
            error_log('[MSGraphClient] Token saved to system settings');

            // Also update in-memory cache for this request
            $this->accessToken = $token;
            $this->accessTokenExpiresAt = (int)$expirationTs;

            error_log('[MSGraphClient] Token acquisition successful');
            return $token;
        } catch (GuzzleException $e) {
            error_log('[MSGraphClient] GuzzleException during token request: ' . $e->getMessage());
            error_log('[MSGraphClient] Request: ' . $e->getRequest()->getUri());
            if ($e->hasResponse()) {
                error_log('[MSGraphClient] Response status: ' . $e->getResponse()->getStatusCode());
                error_log('[MSGraphClient] Response body: ' . substr((string)$e->getResponse()->getBody(), 0, 500));
            }
            return null;
        } catch (\Throwable $e) {
            error_log('[MSGraphClient] Unexpected exception during token request: ' . $e->getMessage());
            error_log('[MSGraphClient] Exception class: ' . get_class($e));
            error_log('[MSGraphClient] Stack trace: ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Return true if the user should be ignored based on first/last name.
     * Rule: if givenName OR surname is a single word that exactly matches one of $ignoreNameWords.
     */
    private function shouldIgnoreByName(array $user): bool
    {
        $given = isset($user['givenName']) ? trim((string)$user['givenName']) : '';
        $sur = isset($user['surname']) ? trim((string)$user['surname']) : '';

        $check = function (string $name): bool {
            if ($name === '') {
                return false;
            }

            // Normalize spacing and case
            $name = strtolower(preg_replace('/\s+/', ' ', $name));

            // If the name contains any digits, treat as non-real/service/test and ignore
            if (preg_match('/\d/', $name)) {
                return true;
            }

            // Only apply ignore-word rule when it's exactly ONE word
            if (strpos($name, ' ') !== false) {
                return false;
            }

            return in_array($name, $this->ignoreNameWords, true);
        };

        return $check($given) || $check($sur);
    }

    /**
     * Return true if the user should be ignored based on email/UPN.
     *
     * Rule: if `mail` OR `userPrincipalName` OR any entry in `otherMails` matches one of
     * $ignoreEmailWords (case-insensitive), either as a full address or as the local-part
     * (the portion before '@'), the user is skipped.
     */
    private function shouldIgnoreByEmail(array $user): bool
    {
        $ignore = [];
        foreach ($this->ignoreEmailWords as $w) {
            $w = strtolower(trim((string)$w));
            if ($w !== '') {
                $ignore[$w] = true;
            }
        }
        if (empty($ignore)) {
            return false;
        }

        $candidates = [];
        if (!empty($user['mail'])) {
            $candidates[] = (string)$user['mail'];
        }
        if (!empty($user['userPrincipalName'])) {
            $candidates[] = (string)$user['userPrincipalName'];
        }
        if (!empty($user['otherMails']) && is_array($user['otherMails'])) {
            foreach ($user['otherMails'] as $m) {
                if (!empty($m)) {
                    $candidates[] = (string)$m;
                }
            }
        }

        foreach ($candidates as $raw) {
            $s = strtolower(trim((string)$raw));
            if ($s === '') {
                continue;
            }

            // Full address match
            if (isset($ignore[$s])) {
                return true;
            }

            // Local-part match
            $atPos = strpos($s, '@');
            if ($atPos !== false) {
                $local = trim(substr($s, 0, $atPos));
                if ($local !== '' && isset($ignore[$local])) {
                    return true;
                }
            }
        }

        return false;
    }
}
