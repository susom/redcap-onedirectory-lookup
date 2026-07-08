<?php

namespace Stanford\RedcapOneDirectoryLookup;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

/** @var RedcapOneDirectoryLookup $module */

// Authorize the same way as the search endpoint: a REDCap session OR a webauth-
// authenticated survey respondent (Shibboleth REMOTE_USER + valid survey context).
$access = $module->authorizeLookup();
if (!$access['allow']) {
    http_response_code(403);
    exit('Not authorized');
}

$userId = $_GET['user_id'] ?? null;
$size   = $_GET['size']   ?? '120x120';

// The size segment is placed directly into the Graph URL path; restrict it to the
// documented WxH form so it cannot alter the request path.
if (!preg_match('/^\d{1,4}x\d{1,4}$/', (string)$size)) {
    $size = '120x120';
}

if (!$userId) {
    http_response_code(400);
    exit('user_id required');
}

// The unauthenticated survey path (webauth/dev) gets the same extra restrictions as the
// search endpoint: a per-identity rate limit and an audit trail. Photos are requested
// once per rendered search result, so they use their own, looser throttle bucket to
// avoid breaking legitimate survey rendering while still bounding bulk enumeration.
if ($access['source'] !== 'redcap') {
    $photoLimit = $module->getSurveyLookupRateLimit() * 10;
    if ($module->isActionRateLimited('odlookup_photo', $access['identity'], $photoLimit)) {
        http_response_code(429);
        exit('Too many photo requests. Please wait a moment and try again.');
    }
    $module->logLookupAction('odlookup_photo', $access['identity'], $access['source'], (string)$userId);
}

try {
    // Get access token (with system settings caching)
    $msGraphClient = $module->getMSGraphClient();
    $accessToken = $msGraphClient->getAccessToken();

    if (!$accessToken) {
        http_response_code(401);
        exit('Failed to obtain access token');
    }

    // Make direct HTTP request to get photo
    $graphUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode($userId) . '/photos/' . urlencode($size) . '/$value';

    $http = new GuzzleClient([
        'timeout' => 10.0,
        'connect_timeout' => 5.0,
    ]);

    $response = $http->get($graphUrl, [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'image/jpeg',
        ],
    ]);

    $statusCode = $response->getStatusCode();
    if ($statusCode !== 200) {
        http_response_code(404);
        exit('Photo not found');
    }

    $bytes = (string)$response->getBody();
    if (!$bytes) {
        http_response_code(404);
        exit('Empty photo response');
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    echo $bytes;
} catch (GuzzleException $e) {
    // No photo or no permission
    http_response_code(404);
    exit('Photo request failed: ' . $e->getMessage());
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Error: ' . $e->getMessage());
}
