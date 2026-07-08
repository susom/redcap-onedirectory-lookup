# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.


NEVER USE SUBAGENT FOR ANY TASK UNLESS THE USER EXPLICITLY ASKS FOR IT.

For reference, read ../REDCAP_TECHNICAL_GUIDANCE.md for general REDCap core guidance. and ../EXTERNAL_MODULES_INDEX.md for general External Module guidance.
## What this is

A Stanford REDCap External Module (EM) that adds a person-lookup autocomplete to REDCap data entry forms and surveys. It searches Microsoft Graph (app-only / client-credentials) for Stanford-affiliated users and populates mapped REDCap fields from the selected result. It replaces a decommissioned ElasticSearch "OneDirectory" service — legacy attribute names (`OneDirectoryId`, `affiliate`, `suid`, `first_name`, etc.) are still supported as aliases for backward compatibility and must not be removed.

Active development happens on the `ms-graph-api` branch; `master` is the default branch for PRs.

## Development environment & commands

- There is no build, lint, or test tooling. The module runs inside a REDCap instance — this checkout lives at `www/modules-local/redcap_onedirectory_lookup_v9.9.9/` inside a parent `redcap-docker-compose` environment. The `_v9.9.9` directory suffix is the REDCap EM framework's version-directory convention; changing it breaks module loading.
- Dependencies: `composer install` / `composer update`. **`vendor/` is committed to git** (REDCap modules deploy with vendor included), so commit vendor changes along with `composer.lock`.
- Testing is manual, through the REDCap UI: enable the module on a project, configure field mappings in the EM settings, and exercise a data entry form or survey containing a configured search field.
- Logging goes through `emDebug()`/`emLog()`/`emError()` from `emLoggerTrait.php` (never `error_log`). Output only appears if the `em_logger` module is installed and the "Enable Debug Logging" system/project setting is on.

## Architecture

Request flow, end to end:

1. **Hook entry** — `RedcapOneDirectoryLookup.php` implements `redcap_data_entry_form_top` and `redcap_survey_page_top`. Both call `processFields()`, which builds `$fieldsMap` from the repeatable `instance` sub-settings in `config.json` and, if the current instrument contains any configured search field, includes `view/fields.php`.
2. **UI injection** — `view/fields.php` emits `assets/js/fields.js` plus a `Fields.list = <json fieldsMap>` bootstrap. `fields.js` attaches a jQuery UI autocomplete to each configured search field, handles scroll-to-end pagination via Graph `@odata.nextLink`, and on selection writes attribute values into the mapped REDCap fields (`Fields.fillInformation()`, with dot-path support like `manager.displayName`).
3. **AJAX endpoints** (`ajax/`) — declared as `no-auth-pages` in `config.json`, so they are publicly reachable; be careful about what they expose. `get_users.php` → `$module->searchUsers()`; `get_user_photo.php` and `get_user_manager.php` call Graph directly per-user (manager is fetched lazily on selection, not via `$expand`).
4. **Graph client** — `classes/MSGraphClient.php` does **not** use the Microsoft Graph SDK despite it being in composer.json; it makes raw Guzzle calls to `https://graph.microsoft.com/v1.0` with `$search`/`$filter`/`ConsistencyLevel: eventual`. It normalizes each Graph user into a flat array that includes both new Graph attribute names and the legacy OneDirectory aliases (see `getUsersByFilter()` — this mapping is the compatibility contract with existing project configurations).
5. **Credentials** — `classes/GoogleSecretManager.php` fetches `MS_GRAPH_TENANT_ID`, `MS_GRAPH_CLIENT_ID`, `MS_GRAPH_CLIENT_SECRET` from Google Secret Manager (GCP project id comes from the `google-cloud-project-id` system setting). It has a deliberate fallback chain: gRPC/REST SDK client → pure-HTTP REST call using the GCE/GKE metadata-server token (works around `GPBDecodeException` and missing gRPC extension in production; `GSM_TRANSPORT` env var can force a transport). The Graph access token is cached in the REDCap **system settings** `microsoft-graph-access-token` / `...-expiration-timestamp` with a 60-second refresh safety window, so it is shared across requests and PHP processes.

### Filtering rules (business logic that lives in MSGraphClient)

- Default search (no affiliation selected) OR-combines three Stanford org filters: SHC employees (`S0*` UPN at `@stanfordhealthcare.org`, excluding `-a@` admin accounts), and Guest users at `@stanfordchildrens.org` and `@stanford.edu`.
- Affiliation enforcement maps codes `1`/`2`/`3` → Stanford Children's Health / Stanford Health Care / Stanford University (`$companyNameMap`); the code can come from EM settings or from a survey field value (see `enforce-affiliation` settings in config.json and the corresponding listener logic in `fields.js`).
- Results are post-filtered: non-Stanford emails, digit-containing names, and the `$ignoreNameWords`/`$ignoreEmailWords` service-account lists are dropped.
- `mailNickname`/`suid` is computed, not taken from Graph: local-part of `mail` for SU/Children's, local-part of `userPrincipalName` for SHC (`computeMailNickname()`).

### Configuration model (config.json)

Project settings use a repeatable `instance` sub-setting (one per search field) each containing a repeatable `attribute_instance` map (Graph attribute → REDCap field). Note that `processInstances()` reads these via `getSubSettings('instance')` **and** the parallel flat arrays `getProjectSetting("one-directory-attribute")` / `getProjectSetting("mapped-field")` indexed by `[$instance][$attribute]` — keep those in sync if you touch the settings schema.
