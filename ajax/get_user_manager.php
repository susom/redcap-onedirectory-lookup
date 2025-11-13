<?php

namespace Stanford\RedcapOneDirectoryLookup;

use Google\ApiCore\ApiException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/** @var RedcapOneDirectoryLookup $module */
try{
    $userId = $_GET['user_id'] ?? null;
    if (!$userId) {
        throw new \Exception("Missing user_id parameter");
    }
    $graphClient = $module->getMSGraphClient()->getGraphClient();
    $mgrObj = $graphClient->users()->byUserId($userId)->manager()->get()->wait();
    if ($mgrObj) {
        $manager = [
            'id' => method_exists($mgrObj, 'getId') ? $mgrObj->getId() : null,
            'displayName' => method_exists($mgrObj, 'getDisplayName') ? $mgrObj->getDisplayName() : null,
            'mail' => method_exists($mgrObj, 'getMail') ? $mgrObj->getMail() : null,
            'userPrincipalName' => method_exists($mgrObj, 'getUserPrincipalName') ? $mgrObj->getUserPrincipalName() : null,
        ];
    }
    echo json_encode(array('status' => 'success', 'manager' => $manager ?? null));
}catch (\Exception | GuzzleException | RequestException |ApiException $e){
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => $e->getResponse()));
    if ($e->hasResponse()) {
        echo json_encode(array('status' => 'error', 'message' => $e->getResponse()));
    }
}
