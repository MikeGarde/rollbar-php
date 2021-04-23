<?php namespace Rollbar\Senders;

/**
 * Adapted from:
 * https://github.com/segmentio/analytics-php/blob/master/lib/Segment/Consumer/Socket.php
 */

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Rollbar\Response;
use Rollbar\Payload\Payload;
use Rollbar\Payload\EncodedPayload;

class CurlSender implements SenderInterface
{
    private $utilities;
    private $endpoint;
    private $timeout;
    private $proxy = null;
    private $verifyPeer = true;
    private $caCertPath = null;
    private $multiHandle = null;
    private $maxBatchRequests = 75;
    private $batchRequests = array();
    private $inflightRequests = array();

    public function __construct($opts)
    {
        $this->endpoint = \Rollbar\Defaults::get()->endpoint() . 'item/';
        $this->timeout = \Rollbar\Defaults::get()->timeout();
        
        $this->utilities = new \Rollbar\Utilities();
        if (isset($_ENV['ROLLBAR_ENDPOINT']) && !isset($opts['endpoint'])) {
            $opts['endpoint'] = $_ENV['ROLLBAR_ENDPOINT'];
        }
        if (array_key_exists('endpoint', $opts)) {
            $this->utilities->validateString($opts['endpoint'], 'opts["endpoint"]', null, false);
            $this->endpoint = $opts['endpoint'];
        }
        if (array_key_exists('timeout', $opts)) {
            $this->utilities->validateInteger($opts['timeout'], 'opts["timeout"]', 0, null, false);
            $this->timeout = $opts['timeout'];
        }
        if (array_key_exists('proxy', $opts)) {
            $this->proxy = $opts['proxy'];
        }

        if (array_key_exists('verifyPeer', $opts)) {
            $this->utilities->validateBoolean($opts['verifyPeer'], 'opts["verifyPeer"]', false);
            $this->verifyPeer = $opts['verifyPeer'];
        }
        if (array_key_exists('ca_cert_path', $opts)) {
            $this->caCertPath = $opts['ca_cert_path'];
        }
    }
    
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function send(EncodedPayload $payload, $logLocation): Response
    {
        $logger = new Logger('rollbar');

        if (method_exists('DDTrace', 'trace_id')) {
            $logger->pushProcessor(function ($record) {
                $record['dd'] = [
                    'trace_id' => \DDTrace\trace_id(),
                    'span_id'  => \dd_trace_peek_span_id(),
                ];

                return $record;
            });
        }

        try {
            $level  = $payload->data()->getLevel();
            $levels = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

            if (!in_array($level, $levels)) {
                $level = 'INFO';
            }
        }
        catch (\Exception $e) {
            $level = 'INFO';
        }

        $logger->pushHandler(new StreamHandler($logLocation, Logger::$$level));

        $level = strtolower($level);
        $logger->$$level($payload);

        return new Response(200, '', null);
    }

    public function sendBatch($batch, $accessToken)
    {
        if ($this->multiHandle === null) {
            $this->multiHandle = curl_multi_init();
        }

        if ($this->maxBatchRequests > 0) {
            $this->wait($accessToken, $this->maxBatchRequests);
        }

        $this->batchRequests = array_merge($this->batchRequests, $batch);
        $this->maybeSendMoreBatchRequests($accessToken);
        $this->checkForCompletedRequests($accessToken);
    }

    public function wait($accessToken, $max = 0)
    {
        if (count($this->inflightRequests) <= $max) {
            return;
        }
        while (1) {
            $this->checkForCompletedRequests($accessToken);
            if (count($this->inflightRequests) <= $max) {
                break;
            }
            curl_multi_select($this->multiHandle); // or do: usleep(10000);
        }
    }

    private function maybeSendMoreBatchRequests($accessToken)
    {
        $max = $this->maxBatchRequests - count($this->inflightRequests);
        if ($max <= 0) {
            return;
        }
        $idx = 0;
        $len = count($this->batchRequests);
        for (; $idx < $len && $idx < $max; $idx++) {
            $payload = $this->batchRequests[$idx];
            $handle = curl_init();
            $this->setCurlOptions($handle, $payload, $accessToken);
            curl_multi_add_handle($this->multiHandle, $handle);
            $handleArrayKey = (int)$handle;
            $this->inflightRequests[$handleArrayKey] = true;
        }
        $this->batchRequests = array_slice($this->batchRequests, $idx);
    }

    public function setCurlOptions($handle, EncodedPayload $payload, $accessToken)
    {
        curl_setopt($handle, CURLOPT_URL, $this->endpoint);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload->encoded());
        curl_setopt($handle, CURLOPT_VERBOSE, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array('X-Rollbar-Access-Token: ' . $accessToken));
        curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        if (!is_null($this->caCertPath)) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caCertPath);
        }

        if ($this->proxy) {
            $proxy = is_array($this->proxy) ? $this->proxy : array('address' => $this->proxy);
            if (isset($proxy['address'])) {
                curl_setopt($handle, CURLOPT_PROXY, $proxy['address']);
                curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
            }
            if (isset($proxy['username']) && isset($proxy['password'])) {
                curl_setopt($handle, CURLOPT_PROXYUSERPWD, $proxy['username'] . ':' . $proxy['password']);
            }
        }
    }

    private function checkForCompletedRequests($accessToken)
    {
        do {
            $curlResponse = curl_multi_exec($this->multiHandle, $active);
        } while ($curlResponse == CURLM_CALL_MULTI_PERFORM);
        while ($active && $curlResponse == CURLM_OK) {
            if (curl_multi_select($this->multiHandle, 0.01) == -1) {
                $this->maybeSendMoreBatchRequests($accessToken);
                return;
            }
            do {
                $curlResponse = curl_multi_exec($this->multiHandle, $active);
            } while ($curlResponse == CURLM_CALL_MULTI_PERFORM);
        }
        $this->removeFinishedRequests($accessToken);
    }

    private function removeFinishedRequests($accessToken)
    {
        while ($info = curl_multi_info_read($this->multiHandle)) {
            $handle = $info['handle'];
            $handleArrayKey = (int)$handle;
            if (isset($this->inflightRequests[$handleArrayKey])) {
                unset($this->inflightRequests[$handleArrayKey]);
                curl_multi_remove_handle($this->multiHandle, $handle);
            }
            curl_close($handle);
        }
        $this->maybeSendMoreBatchRequests($accessToken);
    }
    
    public function toString()
    {
        return "Rollbar API endpoint: " . $this->getEndpoint();
    }
}
