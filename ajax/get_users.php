<?php

namespace Stanford\RedcapOneDirectoryLookup;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

/** @var RedcapOneDirectoryLookup $module */

try {
    $term = htmlentities($_GET['term']);
    $nextLink = $_GET['next_page'];

    $response = $module->searchUsers($term, $nextLink);
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
