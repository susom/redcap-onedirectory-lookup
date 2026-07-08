<?php

namespace Stanford\RedcapOneDirectoryLookup;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

/** @var RedcapOneDirectoryLookup $module */

// Empty result payload (same shape MSGraphClient returns) used whenever we refuse
// to query Graph. Refusing here means no directory data leaves the server.
$emptyResult = [
    'count' => null,
    'users' => [],
    'preview' => [],
    'nextLink' => null,
    'prevLink' => null,
    '@odata.nextLink' => null,
];

// Authorize: allow a full REDCap session (data-entry forms / logged-in surveys) OR a
// webauth-authenticated survey respondent (Shibboleth REMOTE_USER + valid survey context).
// Fully anonymous requests are denied here, closing the directory-enumeration hole.
$access = $module->authorizeLookup();
if (!$access['allow']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not authorized for lookup', 'reason' => $access['reason']]);
    return;
}
// The unauthenticated survey path (webauth/dev) gets extra restrictions: rate limiting,
// audit logging, and no pagination (data minimization). Logged-in REDCap users do not.
$isSurveyPath = ($access['source'] !== 'redcap');

try {
    // Neutralize the search term: allow only characters meaningful for names/emails.
    // This strips KQL/OData control characters (quotes, parentheses, colons, etc.) so
    // the term cannot alter the structure of the Microsoft Graph query (CWE-943/CWE-74).
    $rawTerm = isset($_GET['term']) ? (string)$_GET['term'] : '';
    $term = preg_replace('/[^\p{L}\p{N}\s@._\'-]/u', ' ', $rawTerm);
    $term = trim(preg_replace('/\s+/u', ' ', (string)$term));

    // Require a minimum meaningful length. This rejects punctuation-only terms and
    // single-character wildcards that would otherwise enumerate the directory.
    if (mb_strlen($term) < 2) {
        echo json_encode($emptyResult);
        return;
    }

    // For the survey (webauth) path: enforce a per-identity rate limit and record an
    // audit trail of who searched for what.
    if ($isSurveyPath) {
        if ($module->isLookupRateLimited($access['identity'])) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Too many lookups. Please wait a moment and try again.']);
            return;
        }
        $module->logLookup($access['identity'], $access['source'], $term);
    }

    // companyName is used to pick a fixed org filter; restrict it to the known codes.
    $companyName = isset($_GET['companyName']) ? (string)$_GET['companyName'] : '';
    if (!in_array($companyName, ['', '1', '2', '3'], true)) {
        $companyName = '';
    }

    if ($isSurveyPath) {
        // Data minimization: no pagination for survey respondents. Combined with the
        // 10-result page cap, this prevents bulk directory extraction per search term.
        $nextLink = null;
    } else {
        // next_page must be an absolute Microsoft Graph pagination URL. Rejecting anything
        // else prevents SSRF / bearer-token exfiltration (the Graph access token is attached
        // to whatever URL this becomes). The authoritative check is in MSGraphClient.
        $nextLink = isset($_GET['next_page']) ? (string)$_GET['next_page'] : '';
        if ($nextLink !== '') {
            $parts = parse_url($nextLink);
            $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
            $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
            if ($scheme !== 'https' || $host !== 'graph.microsoft.com') {
                echo json_encode($emptyResult);
                return;
            }
        } else {
            $nextLink = null;
        }
    }

    $response = $module->searchUsers($term, $nextLink, $companyName);

    if ($isSurveyPath && is_array($response)) {
        // Strip pagination links so survey clients cannot walk the directory.
        $response['nextLink'] = null;
        $response['prevLink'] = null;
        $response['@odata.nextLink'] = null;
    }

    echo json_encode($response);
} catch (\LogicException $e) {
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
} catch (RequestException $e) {
    echo json_encode(array('status' => 'error', 'message' => Psr7\str($e->getResponse())));
    if ($e->hasResponse()) {
        echo json_encode(array('status' => 'error', 'message' => Psr7\str($e->getResponse())));
    }
} catch (\Exception $e) {
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
} catch (GuzzleException $e) {
    echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
}
