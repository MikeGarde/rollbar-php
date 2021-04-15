<?php declare(strict_types=1);

namespace Rollbar\Payload;

use Rollbar\DataBuilder;
use Rollbar\Config;
use Rollbar\UtilitiesTrait;

class Payload implements \Serializable
{
    use UtilitiesTrait;

    public function __construct(private Data $data, private $accessToken)
    {
    }

    /**
     * @return Data
     */
    public function getData()
    {
        return $this->data;
    }

    public function setData(Data $data)
    {
        $this->data = $data;
        return $this;
    }

    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    public function serialize($maxDepth = -1)
    {
        $objectHashes = array();
        $result = array(
            "data" => $this->data,
            "access_token" => $this->accessToken,
        );

        return $this->utilities()->serializeForRollbar($result, null, $objectHashes, $maxDepth);
    }
    
    public function unserialize(string $serialized)
    {
        throw new \Exception('Not implemented yet.');
    }
}
