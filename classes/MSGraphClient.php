<?php

namespace Stanford\RedcapOneDirectoryLookup;


use Google\ApiCore\ApiException;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetQueryParameters;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetRequestConfiguration;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Abstractions\HttpMethod;
use Microsoft\Graph\Generated\Models\UserCollectionResponse;


class MSGraphClient
{
    const MS_GRAPH_CLIENT_ID = 'MS_GRAPH_CLIENT_ID';
    const MS_GRAPH_TENANT_ID = 'MS_GRAPH_TENANT_ID';
    const MS_GRAPH_CLIENT_SECRET = 'MS_GRAPH_CLIENT_SECRET';
    private $secretManager;

    private $client;

    private $attributes;

    private $SUImage;

    private $SoMImage;
    /**
     * Default attributes to $select for users queries.
     * Note: Navigation property `manager` is handled via $expand and not included here.
     */
    private const DEFAULT_ATTRIBUTES = [
        'id','displayName','givenName','surname','mail','userPrincipalName','accountEnabled',
        'jobTitle','department','companyName','officeLocation','businessPhones','mobilePhone',
        'preferredLanguage','identities','otherMails','mailNickname','usageLocation','createdDateTime',
        'assignedLicenses','assignedPlans','onPremisesExtensionAttributes','streetAddress','city','state',
        'postalCode','country','physicalDeliveryOfficeName','telephoneNumber','userType','showInAddressList'
    ];

    private $module;
    public function __construct(GoogleSecretManager $secretManager, $module, $attributes = [])
    {
        $this->module = $module;
        $this->secretManager = $secretManager;
        $this->attributes = $attributes && is_array($attributes) && count($attributes) > 0
            ? array_values(array_unique(array_map('trim', $attributes)))
            : self::DEFAULT_ATTRIBUTES;
    }

    /**
     * @throws ApiException
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
     * Build OData filter for user search.
     *
     * @param string $searchTerm
     * @return string
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
     * Convenience method: search by term across UPN, mailNickname, and mail.
     *
     * @param string $searchTerm
     * @return array{count:int|null, users:array}
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

    private function getSUImage()
    {
        if(!$this->SUImage) {
            $this->SUImage = $this->module->getUrl('assets/images/stanford_university.png', true, true);
        }
        return $this->SUImage;
    }

    private function getSoMImage()
    {
        if(!$this->SoMImage) {
            $this->SoMImage = $this->module->getUrl('assets/images/stanford_medicine.png', true, true);

        }
        return $this->SoMImage;
    }
}
