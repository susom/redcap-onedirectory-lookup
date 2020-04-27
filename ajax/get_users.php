<?php

namespace Stanford\RedcapOneDirectoryLookup;

use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

/** @var \Stanford\RedcapOneDirectoryLookup\RedcapOneDirectoryLookup $module */

try {
    $term = filter_var($_GET['term'], FILTER_SANITIZE_STRING);
    $response = $module->searchUsers($term);
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
}
