<?php declare(strict_types=1);

namespace Rollbar\Payload;

use Rollbar\UtilitiesTrait;

class Body implements \Serializable
{
    use UtilitiesTrait;

    public function __construct(
        private ContentInterface $value,
        private array $extra = array()
    ) {
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue(ContentInterface $value)
    {
        $this->value = $value;
        return $this;
    }
    
    public function setExtra(array $extra)
    {
        $this->extra = $extra;
        return $this;
    }
    
    public function getExtra()
    {
        return $this->extra;
    }

    public function serialize()
    {
        $result = array();
        $result[$this->value->getKey()] = $this->value;
        
        if (!empty($this->extra)) {
            $result['extra'] = $this->extra;
        }
        
        return $this->utilities()->serializeForRollbarInternal($result, array('extra'));
    }
    
    public function unserialize(string $serialized)
    {
        throw new \Exception('Not implemented yet.');
    }
}
