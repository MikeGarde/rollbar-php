<?php namespace Rollbar\Payload;

class TraceChain implements ContentInterface
{
    public function __construct(private array $traces)
    {
    }

    public function getKey()
    {
        return 'trace_chain';
    }

    public function getTraces()
    {
        return $this->traces;
    }

    public function setTraces($traces)
    {
        $this->traces = $traces;
        return $this;
    }

    public function serialize()
    {
        $mapValue = function ($value) {
            if ($value instanceof \Serializable) {
                return $value->serialize();
            }
            return $value;
        };
        return array_map($mapValue, $this->traces);
    }
    
    public function unserialize($serialized)
    {
        throw new \Exception('Not implemented yet.');
    }
}
