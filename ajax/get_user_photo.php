<?php

namespace Stanford\RedcapOneDirectoryLookup;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

/** @var RedcapOneDirectoryLookup $module */

// photo.php (module page)
$userId = $_GET['user_id'] ?? null;
$size   = $_GET['size']   ?? '120x120';
$company = $_GET['companyName'] ?? null;
if (!$userId) { http_response_code(400); exit('user_id required'); }

try {
    $graph = $module->getMSGraphClient()->getGraphClient();
    $stream = $graph->users()
        ->byUserId($userId)
        ->photos()
        ->byProfilePhotoId($size)
        ->content()
        ->get()
        ->wait();

    // Read PSR-7 stream safely
    if ($stream instanceof \Psr\Http\Message\StreamInterface) {
        $stream->rewind();
        $bytes = $stream->getContents();
    } else {
        $bytes = is_resource($stream) ? stream_get_contents($stream) : null;
    }

    if (!$bytes) { http_response_code(404); exit; }

    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    echo $bytes;
} catch (\Throwable $e) {
    // No photo or no permission
    if ($company == "Stanford University") {
        $image = $module->getUrl('assets/images/stanford_university.png', true, true);
    } else {
        $image = $module->getUrl('assets/images/stanford_medicine.png', true, true);
    }
    $imageContent = @file_get_contents($image);
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    echo $imageContent;
}
