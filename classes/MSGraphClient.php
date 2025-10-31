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
    const MS_GRAPH_CLIENT_ID = 'MS_GRAPH_CLIENT_ID';
    const MS_GRAPH_TENANT_ID = 'MS_GRAPH_TENANT_ID';
    const MS_GRAPH_CLIENT_SECRET = 'MS_GRAPH_CLIENT_SECRET';
    /**
     * Google Secret Manager wrapper used to retrieve Graph app credentials.
     *
     * @var GoogleSecretManager
     */
    private $secretManager;

    /**
     * Lazily-initialized GraphServiceClient instance.
     *
     * @var GraphServiceClient|null
     */
    private $client;

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
     * @param GoogleSecretManager $secretManager Secret source for Graph app credentials.
     * @param mixed               $module        REDCap EM instance used to build asset URLs.
     * @param string[]            $attributes    Optional override for `$select` attributes.
     */
    public function __construct(GoogleSecretManager $secretManager, $module, $attributes = [])
    {
        $this->module = $module;
        $this->secretManager = $secretManager;
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
                $this->secretManager->getSecret(self::MS_GRAPH_TENANT_ID),
                $this->secretManager->getSecret(self::MS_GRAPH_CLIENT_ID),
                $this->secretManager->getSecret(self::MS_GRAPH_CLIENT_SECRET)
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
            top: 20
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
        // Escape single quotes by doubling them per OData rules
        $escaped = str_replace("'", "''", trim($searchTerm));

        return sprintf(
            "startsWith(userPrincipalName,'%s') or startsWith(mailNickname,'%s') or startsWith(mail,'%s') or startsWith(givenName,'%s') or startsWith(surname,'%s')",
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
     * @param string      $searchTerm Term to search (prefix match via startsWith).
     * @param string|null $nextLink   Optional absolute `@odata.nextLink` for subsequent pages.
     * @return array{count:int|null, users:array, preview:array, nextLink:?string, prevLink:?string, @odata.nextLink:?string}
     * @throws ApiException
     * @throws \Exception
     * @throws \Throwable
     */
    public function searchUsers(string $searchTerm, $nextLink = null): array
    {
        $filter = $this->buildSearchFilter($searchTerm);
        return $this->getUsersByFilter($filter, $nextLink);
    }


    /**
     * Execute a users query given an explicit OData filter and optional nextLink for pagination.
     *
     * If `$nextLink` is provided, a raw RequestInformation with the absolute URL is used to bypass
     * SDK pagination gaps (e.g., missing skiptoken support in some versions).
     *
     * @param string      $filter   OData filter expression.
     * @param string|null $nextLink Absolute `@odata.nextLink` from a previous response (optional).
     * @return array{count:int|null, users:array, preview:array, nextLink:?string, prevLink:?string, @odata.nextLink:?string}
     * @throws ApiException
     * @throws \Exception
     * @throws \Throwable
     */
    public function getUsersByFilter($filter, $nextLink)
    {
        $graphClient = $this->getGraphClient();
        $queryParams = $this->getQueryParams($filter);


        $requestConfig = new UsersRequestBuilderGetRequestConfiguration();
        $requestConfig->queryParameters = $queryParams;

        // No ConsistencyLevel header needed since count=false

        if (!is_null($nextLink)) {
            // Use a raw RequestInformation against the absolute nextLink URL.
            // This bypasses SDK query-parameter gaps (e.g., missing skiptoken property).
            $requestInfo = new RequestInformation();
            $requestInfo->httpMethod = HttpMethod::GET;
            $requestInfo->urlTemplate = $nextLink;  // absolute URL
            $requestInfo->pathParameters = [];
            $requestInfo->addHeaders(['Accept' => 'application/json']);

            // Send and parse into a UserCollectionResponse
            $response = $graphClient->getRequestAdapter()
                ->sendAsync($requestInfo, [UserCollectionResponse::class, 'createFromDiscriminatorValue'])
                ->wait();
        } else {
            // First page
            $response = $graphClient->users()->get($requestConfig)->wait();
        }


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
            $manager = null;
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

            // Fetch user photo (120x120)
            // Instead of downloading the photo, return the Graph endpoint URL
            $photoUrl = sprintf(
                'https://graph.microsoft.com/v1.0/users/%s/photos/120x120/$value',
                urlencode($user->getId())
            );

            $users[] = [
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
                'mailNickname' => $user->getMailNickname(),
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
                'photoUrl' => $this->module->getUrl('ajax/get_user_photo.php', true, true) . '&user_id=' . urlencode($user->getId()) . '&size=120x120',
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
                'suid' => $user->getMailNickname(),

            ];
        }
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
        $tenantId = trim($this->secretManager->getSecret(self::MS_GRAPH_TENANT_ID));
        $clientId = trim($this->secretManager->getSecret(self::MS_GRAPH_CLIENT_ID));
        $clientSecret = $this->secretManager->getSecret(self::MS_GRAPH_CLIENT_SECRET);
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
