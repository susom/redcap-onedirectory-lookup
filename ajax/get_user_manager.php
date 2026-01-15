<?php

namespace Stanford\RedcapOneDirectoryLookup;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

/** @var RedcapOneDirectoryLookup $module */
try{
    $userId = $_GET['user_id'] ?? null;
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
