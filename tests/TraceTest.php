<?php namespace Rollbar;

use \Mockery as m;
use Rollbar\Payload\Trace;
use Rollbar\Payload\Frame;

class TraceTest extends \PHPUnit_Framework_TestCase
{
    public function testTraceConstructor()
    {
        $exc = m::mock("Rollbar\Payload\ExceptionInfo");
        $frames = array(m::mock("Rollbar\Payload\Frame"));
        $badFrames = array(1);

        $trace = new Trace(array(), $exc);
        $this->assertEquals(array(), $trace->getFrames());
        $this->assertEquals($exc, $trace->getException());

        $trace = new Trace($frames, $exc);
        $this->assertEquals($frames, $trace->getFrames());
        $this->assertEquals($exc, $trace->getException());

        try {
            $trace = new Trace($badFrames, $exc);
            $this->fail("Above should throw");
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals("\$frames must all be Rollbar\Payload\Frames", $e->getMessage());
        }
    }

    public function testFrames()
    {
        $frames = array(
            new Frame("one.php"),
            new Frame("two.php")
        );
        $exc = m::mock("Rollbar\Payload\ExceptionInfo");
        $trace = new Trace(array(), $exc);
        $this->assertEquals($frames, $trace->setFrames($frames)->getFrames());
    }

    public function testException()
    {
        $exc = m::mock("Rollbar\Payload\ExceptionInfo");
        $trace = new Trace(array(), $exc);
        $this->assertEquals($exc, $trace->getException());

        $exc2 = m::mock("Rollbar\Payload\ExceptionInfo");
        $this->assertEquals($exc2, $trace->setException($exc2)->getException());
    }

    public function testEncode()
    {
        $value = m::mock("Rollbar\Payload\ExceptionInfo, \JsonSerializable")
            ->shouldReceive("jsonSerialize")
            ->andReturn("{EXCEPTION}")
            ->mock();
        $trace = new Trace(array(), $value);
        $encoded = json_encode($trace->jsonSerialize());
        $this->assertEquals("{\"frames\":[],\"exception\":\"{EXCEPTION}\"}", $encoded);
    }

    public function testTraceKey()
    {
        $trace = new Trace(array(), m::mock("Rollbar\Payload\ExceptionInfo"));
        $this->assertEquals("trace", $trace->getKey());
    }
}
