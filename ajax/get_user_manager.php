<?php

namespace Stanford\RedcapOneDirectoryLookup;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

/** @var Stanford\RedcapOneDirectoryLookup\RedcapOneDirectoryLookup $module */

// Authorize the same way as the search endpoint: a REDCap session OR a webauth-
// authenticated survey respondent (Shibboleth REMOTE_USER + valid survey context).
$access = $module->authorizeLookup();
if (!$access['allow']) {
    http_response_code(403);
    exit('Not authorized');
}

$userId = $_GET['user_id'] ?? null;

// The unauthenticated survey path (webauth/dev) gets the same extra restrictions as the
// search endpoint: a per-identity rate limit and an audit trail of who looked up whom.
// Logged-in REDCap users are not throttled or logged here.
if ($access['source'] !== 'redcap') {
    if ($module->isActionRateLimited('odlookup_manager', $access['identity'], $module->getSurveyLookupRateLimit())) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many lookups. Please wait a moment and try again.']);
        return;
    }
    $module->logLookupAction('odlookup_manager', $access['identity'], $access['source'], (string)($userId ?? ''));
}

try{
    if (!$userId) {
        throw new \Exception("Missing user_id parameter");
    }

    // Get access token (with system settings caching)
    $msGraphClient = $module->getMSGraphClient();
    $accessToken = $msGraphClient->getAccessToken();

    if (!$accessToken) {
        throw new \Exception("Failed to obtain access token");
    }

    // Make direct HTTP request to get manager info
    $graphUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode($userId) . '/manager';

    $http = new GuzzleClient([
        'timeout' => 10.0,
        'connect_timeout' => 5.0,
    ]);

    $response = $http->get($graphUrl, [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
    ]);

    $statusCode = $response->getStatusCode();
    if ($statusCode !== 200) {
        throw new \Exception("Manager not found or no permission");
    }

    $body = (string)$response->getBody();
    $managerData = json_decode($body, true);

    $manager = null;
    if (!empty($managerData)) {
        $manager = [
            'id' => $managerData['id'] ?? null,
            'displayName' => $managerData['displayName'] ?? null,
            'mail' => $managerData['mail'] ?? null,
            'userPrincipalName' => $managerData['userPrincipalName'] ?? null,
        ];
    }

    echo json_encode(['status' => 'success', 'manager' => $manager]);
} catch (GuzzleException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Manager request failed: ' . $e->getMessage()]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
