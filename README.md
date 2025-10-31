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
