# RedcapOneDirectoryLookup
The OneDirectory ElasticSearch service previously used by the RedcapOneDirectoryLookup External Module is being decommissioned and replaced by the Microsoft Graph API. While legacy attributes from the ElasticSearch service will continue to be supported for backward compatibility, a new, broader set of attributes is now available through the Microsoft Graph API, providing enhanced directory search and user information capabilities.

### Migration Notice
- The ElasticSearch API is being retired.
- The Microsoft Graph API now provides directory search and user information.
- Existing mapped attributes from the legacy service will continue to work.
- Additional attributes are available through the Microsoft Graph API for expanded functionality.

### Features
1. Comprehensive Search: Search across all available attributes in the OneDirectory database.
2. Stanford Affiliates Only: Retrieves information for Stanford University, Stanford Health Care, and Stanford Children's Hospital users.
3. Privacy-Conscious: Excludes private Stanford University users to ensure compliance with privacy standards.
4. Efficient Querying: Uses ElasticSearch for fast and responsive search capabilities.
### Requirements
- REDCap Admin Access: To configure and enable the External Module (EM) on REDCap projects.
- OneDirectory Access: URL endpoint to connect with OneDirectory.
### Configuration
1. Enable the Module: Enable RedcapOneDirectoryLookup on your REDCap project.
2. Define Lookup Field: In the EM settings, specify the main field in your project that will be used for user lookup. This field will contain the search term passed to OneDirectory.
3. Attribute Mapping: Map the OneDirectory attributes in the EM settings to fields in your REDCap project. The module will populate these mapped fields with data returned from OneDirectory.
### Survey Use & Authentication
The lookup endpoints never serve anonymous requests. Access is granted only to:
- **Data entry forms / logged-in surveys:** a normal REDCap session authorizes the lookup automatically. Nothing extra to configure.
- **Public/participant surveys:** the respondent must be authenticated via Stanford webauth (Shibboleth). To enable this:
  1. Enable the **Stanford Webauth** external module on the project and mark the survey's instrument as *Require a valid SUNet ID (webauth)*.
  2. (To record the submitter) add a text field named `webauth_user` to the instrument — the Webauth module fills it with the respondent's SUNet ID.

  With webauth on, the survey is served through the Shibboleth-protected `/webauth` path, so the browser carries the authenticated identity (`REMOTE_USER`). The lookup JS routes its requests through `/webauth` as well, and the endpoints authorize the request only when that identity is present **and** the survey hash resolves to this project. If a survey is *not* webauth-protected, the lookup is disabled on it (fails closed).

Survey (webauth) lookups are additionally **rate-limited** (default 30/min per SUNet, configurable in the system settings), **audit-logged** per SUNet, and returned **without pagination** to prevent bulk directory extraction. Requires the `/webauth` Shibboleth location to proxy the REDCap API endpoints (Stanford production infrastructure).

### Supported Fields
#### Legacy (ElasticSearch) Attributes
The following attributes are currently supported by OneDirectory:

1. OneDirectoryId
2. affiliate
3. jobId
4. first_name
5. last_name
6. fullname
7. phone
8. phone2
9. email
10. title
11. SunetId or SID

#### Microsoft Graph Attributes
The following attributes are available through the Microsoft Graph API:

- id
- displayName
- givenName
- surname
- mail
- userPrincipalName
- accountEnabled
- jobTitle
- department
- companyName
- officeLocation
- businessPhones
- mobilePhone
- preferredLanguage
- identities
- otherMails
- mailNickname
- usageLocation
- createdDateTime
- assignedLicenses
- assignedPlans
- onPremisesExtensionAttributes
- streetAddress
- city
- state
- postalCode
- country
- physicalDeliveryOfficeName
- telephoneNumber
- userType
- showInAddressList
- manager (id, displayName, mail, userPrincipalName)

### License
This project is licensed under the MIT License. See the LICENSE file for more details.
