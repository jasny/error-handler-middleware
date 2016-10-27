<?php

namespace Jasny;

use Jasny\ErrorHandler;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use PHPUnit_Framework_MockObject_MockObject as MockObject;
use PHPUnit_Framework_MockObject_Matcher_InvokedCount as InvokedCount;

/**
 * @covers Jasny\ErrorHandler
 */
class ErrorHandlerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ErrorHandler|MockObject
     */
    protected $errorHandler;
    
    public function setUp()
    {
        $this->errorHandler = $this->getMockBuilder(ErrorHandler::class)
            ->setMethods(['errorReporting', 'errorGetLast', 'setErrorHandler', 'registerShutdownFunction'])
            ->getMock();
    }
    
    /**
     * Test invoke with invalid 'next' param
     * 
     * @expectedException \InvalidArgumentException
     */
    public function testInvokeInvalidNext()
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        
        $errorHandler = $this->errorHandler;
        
        $errorHandler($request, $response, 'not callable');
    }

    /**
     * Test case when there is no error
     */
    public function testInvokeNoError()
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $finalResponse = $this->createMock(ResponseInterface::class);

        $next = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $next->expects($this->once())->method('__invoke')
            ->with($request, $response)
            ->willReturn($finalResponse);
        
        $errorHandler = $this->errorHandler;

        $result = $errorHandler($request, $response, $next);        

        $this->assertSame($finalResponse, $result);
    }
    
    /**
     * Test that Exception in 'next' callback is caught
     */
    public function testInvokeCatchException()
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $errorResponse = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        
        $exception = $this->createMock(\Exception::class);

        $stream->expects($this->once())->method('write')->with('Unexpected error');
        $response->expects($this->once())->method('withStatus')->with(500)->willReturn($errorResponse);

        $errorResponse->expects($this->once())->method('getBody')->willReturn($stream);
        
        $next = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $next->expects($this->once())->method('__invoke')
            ->with($request, $response)
            ->willThrowException($exception);
        
        $errorHandler = $this->errorHandler;
        
        $result = $errorHandler($request, $response, $next);

        $this->assertSame($errorResponse, $result);
        $this->assertSame($exception, $errorHandler->getError());
    }
    
    /**
     * Test that an error in 'next' callback is caught
     */
    public function testInvokeCatchError()
    {
        if (!class_exists('Error')) {
            $this->markTestSkipped(PHP_VERSION . " doesn't throw errors yet");
        }
        
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $errorResponse = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        
        $stream->expects($this->once())->method('write')->with('Unexpected error');
        $response->expects($this->once())->method('withStatus')->with(500)->willReturn($errorResponse);

        $errorResponse->expects($this->once())->method('getBody')->willReturn($stream);
        
        $next = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $next->expects($this->once())->method('__invoke')
            ->with($request, $response)
            ->willReturnCallback(function() {
                \this_function_does_not_exist();
            });
        
        $errorHandler = $this->errorHandler;
        
        $result = $errorHandler($request, $response, $next);

        $this->assertSame($errorResponse, $result);
        
        $error = $errorHandler->getError();
        $this->assertEquals("Call to undefined function this_function_does_not_exist()", $error->getMessage());
    }
    
    
    public function testSetLogger()
    {
        $logger = $this->createMock(LoggerInterface::class);
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);
        
        $this->assertSame($logger, $errorHandler->getLogger());
    }
    
    
    public function testInvokeLog()
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        
        $response->method('withStatus')->willReturnSelf();
        $response->method('getBody')->willReturn($stream);
        
        $exception = $this->createMock(\Exception::class);
        
        $message = $this->stringStartsWith('Uncaught Exception ' . get_class($exception));
        $context = ['exception' => $exception];
        
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')
            ->with(LogLevel::ERROR, $message, $context);
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);
        
        $next = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $next->expects($this->once())->method('__invoke')
            ->with($request, $response)
            ->willThrowException($exception);
        
        $errorHandler($request, $response, $next);
    }
    
    public function errorProvider()
    {
        return [
            [E_ERROR, LogLevel::ERROR, 'Fatal error'],
            [E_USER_ERROR, LogLevel::ERROR, 'Fatal error'],
            [E_RECOVERABLE_ERROR, LogLevel::ERROR, 'Fatal error'],
            [E_WARNING, LogLevel::WARNING, 'Warning'],
            [E_USER_WARNING, LogLevel::WARNING, 'Warning'],
            [E_PARSE, LogLevel::CRITICAL, 'Parse error'],
            [E_NOTICE, LogLevel::NOTICE, 'Notice'],
            [E_USER_NOTICE, LogLevel::NOTICE, 'Notice'],
            [E_CORE_ERROR, LogLevel::CRITICAL, 'Core error'],
            [E_CORE_WARNING, LogLevel::WARNING, 'Core warning'],
            [E_COMPILE_ERROR, LogLevel::CRITICAL, 'Compile error'],
            [E_COMPILE_WARNING, LogLevel::WARNING, 'Compile warning'],
            [E_STRICT, LogLevel::INFO, 'Strict standards'],
            [E_DEPRECATED, LogLevel::INFO, 'Deprecated'],
            [E_USER_DEPRECATED, LogLevel::INFO, 'Deprecated'],
            [99999999, LogLevel::ERROR, 'Unknown error']
        ];
    }
    
    /**
     * @dataProvider errorProvider
     * 
     * @param int    $code
     * @param string $level
     * @param string $type
     */
    public function testLogError($code, $level, $type)
    {
        $error = new \ErrorException("no good", 0, $code, "foo.php", 42);
        $context = ['error' => $error, 'code' => $code, 'message' => "no good", 'file' => 'foo.php', 'line' => 42];
        
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')
            ->with($level, "$type: no good at foo.php line 42", $context);
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log($error);
    }
    
    public function testLogException()
    {
        $exception = $this->createMock(\Exception::class);
        
        $message = $this->stringStartsWith('Uncaught Exception ' . get_class($exception));
        $context = ['exception' => $exception];
        
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')
            ->with(LogLevel::ERROR, $message, $context);
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log($exception);
    }
    
    public function testLogString()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(LogLevel::WARNING, "Unable to log a string");
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log('foo');
    }
    
    public function testLogObject()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with(LogLevel::WARNING, "Unable to log a stdClass object");
        
        $errorHandler = $this->errorHandler;
        $errorHandler->setLogger($logger);

        $errorHandler->log(new \stdClass());
    }
    
    
    public function testConverErrorsToExceptions()
    {
        $errorHandler = $this->errorHandler;

        $errorHandler->expects($this->once())->method('setErrorHandler')
            ->with([$errorHandler, 'errorHandler'])
            ->willReturn(null);
        
        $errorHandler->converErrorsToExceptions();
        
        $this->assertSame(0, $errorHandler->getLoggedErrorTypes());
    }
    
    
    public function alsoLogProvider()
    {
        return [
            [E_ALL, $this->once(), $this->once()],
            [E_WARNING | E_USER_WARNING, $this->once(), $this->never()],
            [E_NOTICE | E_USER_NOTICE, $this->once(), $this->never()],
            [E_STRICT, $this->once(), $this->never()],
            [E_DEPRECATED | E_USER_DEPRECATED, $this->once(), $this->never()],
            [E_PARSE, $this->never(), $this->once()],
            [E_ERROR, $this->never(), $this->once()],
            [E_ERROR | E_USER_ERROR, $this->never(), $this->once()],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, $this->never(), $this->never()]
        ];
    }
    
    /**
     * @dataProvider alsoLogProvider
     * 
     * @param int          $code
     * @param InvokedCount $expectErrorHandler
     * @param InvokedCount $expectShutdownFunction
     */
    public function testAlsoLog($code, InvokedCount $expectErrorHandler, InvokedCount $expectShutdownFunction)
    {
        $errorHandler = $this->errorHandler;
        
        $errorHandler->expects($expectErrorHandler)->method('setErrorHandler')
            ->with([$errorHandler, 'errorHandler'])
            ->willReturn(null);
        
        $errorHandler->expects($expectShutdownFunction)->method('registerShutdownFunction')
            ->with([$errorHandler, 'shutdownFunction']);
        
        $errorHandler->alsoLog($code);
        
        $this->assertSame($code, $errorHandler->getLoggedErrorTypes());
    }
    
    public function testAlsoLogCombine()
    {
        $errorHandler = $this->errorHandler;
        
        $errorHandler->alsoLog(E_NOTICE | E_USER_NOTICE);
        $errorHandler->alsoLog(E_WARNING | E_USER_WARNING);
        $errorHandler->alsoLog(E_ERROR);
        $errorHandler->alsoLog(E_PARSE);
        
        $expected = E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_ERROR | E_PARSE;
        $this->assertSame($expected, $errorHandler->getLoggedErrorTypes());
    }

    public function testInitErrorHandler()
    {
        $errorHandler = $this->errorHandler;
        
        $callback = function() {};
        
        $errorHandler->expects($this->once())->method('setErrorHandler')
            ->with([$errorHandler, 'errorHandler'])
            ->willReturn($callback);
        
        $errorHandler->alsoLog(E_WARNING);
        
        // Subsequent calls should have no effect
        $errorHandler->alsoLog(E_WARNING);
        
        $this->assertSame($callback, $errorHandler->getChainedErrorHandler());
    }
    
    public function testInitShutdownFunction()
    {
        $errorHandler = $this->errorHandler;

        $errorHandler->expects($this->once())->method('registerShutdownFunction')
            ->with([$errorHandler, 'shutdownFunction']);
        
        $errorHandler->alsoLog(E_PARSE);
        
        // Subsequent calls should have no effect
        $errorHandler->alsoLog(E_PARSE);
        
        $this->assertAttributeNotEmpty('reservedMemory', $errorHandler);
    }
    

    public function errorHandlerProvider()
    {
        return [
            [0, E_WARNING, $this->never(), false],
            
            [E_ALL, E_RECOVERABLE_ERROR, $this->once(), true],
            [E_ALL, E_WARNING, $this->once(), false],
            [E_ALL, E_NOTICE, $this->once(), false],
            
            [E_WARNING | E_USER_WARNING, E_RECOVERABLE_ERROR, $this->never(), true],
            [E_WARNING | E_USER_WARNING, E_WARNING, $this->once(), false],
            [E_WARNING | E_USER_WARNING, E_NOTICE, $this->never(), false],
            
            [E_STRICT, E_RECOVERABLE_ERROR, $this->never(), true],
            [E_STRICT, E_STRICT, $this->once(), false],
            
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_RECOVERABLE_ERROR, $this->once(), true],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_WARNING, $this->never(), false],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_NOTICE, $this->never(), false],
            [E_RECOVERABLE_ERROR | E_USER_ERROR, E_STRICT, $this->never(), false]
        ];
    }
    
    /**
     * @dataProvider errorHandlerProvider
     * 
     * @param int          $alsoLog
     * @param int          $code
     * @param InvokedCount $expectsLog
     */
    public function testErrorHandlerWithLogging($alsoLog, $code, InvokedCount $expectsLog)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectsLog)->method('log')
            ->with($this->isType('string'), $this->stringEndsWith("no good at foo.php line 42"), $this->anything());
        
        $errorHandler = $this->errorHandler;
        $errorHandler->expects($this->once())->method('errorReporting')->willReturn(E_ALL | E_STRICT);
        
        $errorHandler->setLogger($logger);
        $errorHandler->alsoLog($alsoLog);
        
        $this->errorHandler->errorHandler($code, 'no good', 'foo.php', 42, []);
    }
    
    /**
     * @dataProvider errorHandlerProvider
     * 
     * @param int          $alsoLog          Ignored
     * @param int          $code
     * @param InvokedCount $expectsLog       Ignored
     * @param boolean      $expectException
     */
    public function testErrorHandlerWithConvertError($alsoLog, $code, InvokedCount $expectsLog, $expectException)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('log');
        
        $errorHandler = $this->errorHandler;
        $errorHandler->expects($this->once())->method('errorReporting')->willReturn(E_ALL | E_STRICT);
        
        $errorHandler->setLogger($logger);
        
        $errorHandler->converErrorsToExceptions();
        
        try {
            $this->errorHandler->errorHandler($code, 'no good', 'foo.php', 42, []);
            
            if ($expectException) {
                $this->fail("Expected error exception wasn't thrown");
            }
        } catch (\ErrorException $exception) {
            if (!$expectException) {
                $this->fail("Error exception shouldn't have been thrown");
            }
            
            $this->assertInstanceOf(\ErrorException::class, $exception);
            $this->assertEquals('no good', $exception->getMessage());
            $this->assertEquals('foo.php', $exception->getFile());
            $this->assertEquals(42, $exception->getLine());
        }
    }
    
    public function shutdownFunctionProvider()
    {
        return [
            [E_ALL, E_PARSE, $this->once()],
            [E_ERROR | E_WARNING, E_PARSE, $this->never()],
            [E_ALL, E_ERROR, $this->once()],
            [E_ALL, E_USER_ERROR, $this->never()],
            [E_ALL, E_WARNING, $this->never()],
            [E_ALL, null, $this->never()]
        ];
    }
    
    /**
     * @dataProvider shutdownFunctionProvider
     * 
     * @param int          $alsoLog          Ignored
     * @param int          $code
     * @param InvokedCount $expectsLog       Ignored
     */
    public function testShutdownFunction($alsoLog, $code, InvokedCount $expectsLog)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expectsLog)->method('log')
            ->with($this->isType('string'), $this->stringEndsWith("no good at foo.php line 42"), $this->anything());
        
        $errorHandler = $this->errorHandler;
        
        $error = [
            'type' => $code,
            'message' => 'no good',
            'file' => 'foo.php',
            'line' => 42
        ];
        
        $errorHandler->expects($this->once())->method('errorGetLast')
            ->willReturn($code ? $error : null);
        
        $errorHandler->setLogger($logger);
        $errorHandler->alsoLog($alsoLog);
        
        $this->errorHandler->shutdownFunction();
    }
}
