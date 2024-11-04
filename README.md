# RedcapOneDirectoryLookup
The RedcapOneDirectoryLookup External Module allows users to search for information within the Stanford University, Stanford Health Care, and Stanford Children's Hospital directories using the OneDirectory Elastic Search engine. This module provides an efficient, privacy-compliant interface for retrieving user information across these organizations.

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

### License
This project is licensed under the MIT License. See the LICENSE file for more details.