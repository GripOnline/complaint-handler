<?php
/**
 *    Copyright 2024 Grip Online
 *
 *    Licensed under the Apache License, Version 2.0 (the "License");
 *    you may not use this file except in compliance with the License.
 *    You may obtain a copy of the License at
 *
 *        http://www.apache.org/licenses/LICENSE-2.0
 *
 *    Unless required by applicable law or agreed to in writing, software
 *    distributed under the License is distributed on an "AS IS" BASIS,
 *    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *    See the License for the specific language governing permissions and
 *    limitations under the License.
 *
 */

/**
 * Complaints message handling script.
 * Set this script as process in .forward for the complaints-eating-user:
 * "|/path/to/bin/php /path/to/process_message.php"
 *
 * @author Erik Hoogeboom
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'complaints.log';

if (!is_file($logFile)) {

    touch($logFile);

}

if (is_file($logFile)) {

    $logger = new Log_file($logFile, 'ProcessMessage');

} else {

    $logger = new Log_null('ProcessMessage');

}

$inputFile = null;

if (array_key_exists('argc', $_SERVER) && array_key_exists('argv', $_SERVER)) {

    if ($_SERVER['argc'] > 1) {

        $inputFile = $_SERVER['argv'][1];

    }

}

$messageFileName = null;
$message = null;

if ($inputFile) {

    if (file_exists($inputFile)) {

        $messageFileName = basename($inputFile);
        $message = file_get_contents($inputFile);

    } else {

        $logger->err('Input file not found: ' . $inputFile);
        exit;

    }

} else {

    $messageFileName = date('YmdHis') . '-' . substr(md5(mt_rand()), 0, 7) . '.msg';
    $message = file_get_contents('php://stdin');

}

$processedMessagesDir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . 'processed' . DIRECTORY_SEPARATOR;
$unknownMessagesDir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . 'unknown' . DIRECTORY_SEPARATOR;

$messageDir = null;

$decode = new Mail_mimeDecode($message);
$args = array('include_bodies' => true);
$decodedObj = $decode->decode($args);

$unsubscribeUrl = null;
$unsubscribeUrlPost = null;
$unsubscribeUrlMailto = null;

$checkHeaderGroups = array();

if ($decodedObj) {

    if (isset($decodedObj->headers) && is_array($decodedObj->headers)) {

        $checkHeaderGroups[] = $decodedObj->headers;

    }

    if (isset($decodedObj->parts) && is_array($decodedObj->parts)) {

        $parts = $decodedObj->parts;

        foreach ($parts as $part) {

            if (isset($part->headers) && is_array($part->headers)) {

                $checkHeaderGroups[] = $part->headers;

            }

            if (isset($part->parts) && is_array($part->parts)) {

                foreach ($part->parts as $subPart) {

                    if (isset($subPart->headers) && is_array($subPart->headers)) {

                        $checkHeaderGroups[] = $subPart->headers;

                    }

                }

            }

        }

    }

} else {

    $logger->err('Unable to decode message ' . $messageFileName);
    $messageDir = $unknownMessagesDir;

}

foreach ($checkHeaderGroups as $headers) {

    if (array_key_exists('list-unsubscribe', $headers)) {

        $listUnsubscribeUrls = explode(',', $headers['list-unsubscribe']);

        foreach ($listUnsubscribeUrls as $listUnsubscribeUrl) {

            $listUnsubscribeUrl = trim($listUnsubscribeUrl);

            if (substr($listUnsubscribeUrl, 0, 1) == '<') {

                $listUnsubscribeUrl = substr($listUnsubscribeUrl, 1);

            }

            if (substr($listUnsubscribeUrl, -1) == '>') {

                $listUnsubscribeUrl = substr($listUnsubscribeUrl, 0, -1);

            }

            if ($listUnsubscribeUrl != '') {

                $parsedUrl = @parse_url($listUnsubscribeUrl);

                if ($parsedUrl) {

                    if (array_key_exists('scheme', $parsedUrl)) {

                        if (($parsedUrl['scheme'] == 'http') || ($parsedUrl['scheme'] == 'https')) {

                            if (array_key_exists('list-unsubscribe-post', $headers) && ($headers['list-unsubscribe-post'] != '')) {

                                if (array_key_exists('query', $parsedUrl)) {

                                    $queryParams = array();
                                    parse_str($parsedUrl['query'], $queryParams);

                                    if (array_key_exists('action', $queryParams) && array_key_exists('token', $queryParams)) {

                                        $unsubscribeUrlPost = $listUnsubscribeUrl;
                                        $unsubscribeUrl = $unsubscribeUrlPost;
                                        break;

                                    }

                                }

                            }

                        } else if ($parsedUrl['scheme'] == 'mailto') {

                            if (array_key_exists('path', $parsedUrl)) {

                                $email = $parsedUrl['path'];

                                if (strpos($email, '@') > 0) {

                                    $userPart = substr($email, 0, strpos($email, '@'));

                                    if (substr($userPart, 0, 12) == 'unsubscribe+') {

                                        $siteParts = explode('-', substr($userPart, 12));

                                        if (count($siteParts) >= 3) {

                                            if (preg_match('/^[1-9]+[0-9]*$/', $siteParts[1])) {

                                                $unsubscribeUrlMailto = $listUnsubscribeUrl;

                                            }

                                        }

                                    }

                                }

                            }

                        }

                    }

                }

            }

        }

    }

    if ($unsubscribeUrl !== null) {
        break;
    }

}

if ($unsubscribeUrl) {

    $logger->info('Unsubsribing user via ' . $unsubscribeUrl . ' for message ' . $messageFileName);
    $messageDir = $processedMessagesDir;

    $opts = array('http' =>
        array(
            'ignore_errors' => true,
            'method' => 'POST'
        )
    );

    $context = stream_context_create($opts);

    $http_response_header = null;

    $result = @file_get_contents($unsubscribeUrl, false, $context);

    $firstHeader = null;

    $responseCode = null;

    if (is_array($http_response_header) && (count($http_response_header) > 0)) {

        $firstHeader = $http_response_header[0];

    }

    if ($firstHeader) {

        $firstHeaderParts = explode(' ', $firstHeader);

        if (count($firstHeaderParts) > 1) {

            $responseCode = $firstHeaderParts[1];

        }

        if (($responseCode == '200') || ($responseCode == '201') || ($responseCode == '302')) {

            // unsubscription succesful
            $logger->info('Successful response for ' . $unsubscribeUrl . ': ' . $firstHeader);

        } else {

            $logger->warning('Unexpected response for ' . $unsubscribeUrl . ': ' . $firstHeader);

        }

    } else {

        $logger->err('Could not call ' . $unsubscribeUrl);

    }

} else if ($unsubscribeUrlMailto !== null) {

    $logger->info('Unsubsribing user via ' . $unsubscribeUrlMailto . ' for message ' . $messageFileName);
    $messageDir = $processedMessagesDir;

    $parsedUrl = parse_url($unsubscribeUrlMailto);

    $to = $parsedUrl['path'];
    $query = $parsedUrl['query'];
    $subject = 'unsubscribe';

    if (array_key_exists('query', $parsedUrl) && ($parsedUrl['query'] != '')) {

        $queryParams = array();
        parse_str($parsedUrl['query'], $queryParams);

        if (array_key_exists('subject', $queryParams)) {

            $subject = $queryParams['subject'];

        }

        $res = @mail($to, $subject, '');

        if ($res) {

            $logger->info('Sent mail to ' . $to . ' for message ' . $messageFileName);

        } else {

            $logger->err('Could not send mail to ' . $to . ' for message ' . $messageFileName);

        }

    }

} else {

    $logger->notice('Unable to determine unsubscribe url from message ' . $messageFileName);
    $messageDir = $unknownMessagesDir;

}

if ($messageDir) {

    if (!is_dir($messageDir)) {

        mkdir($messageDir, 0755, true);

    }

    if (is_dir($messageDir)) {

        $messageFile = $messageDir . $messageFileName;

        file_put_contents($messageFile, $message);

    }

}
