<?php

namespace Syncany\Api\Controller;

use Syncany\Api\Exception\ConfigException;
use Syncany\Api\Exception\Http\BadRequestHttpException;
use Syncany\Api\Exception\Http\UnauthorizedHttpException;
use Syncany\Api\Model\FileHandle;
use Syncany\Api\Util\FileUtil;

abstract class Controller
{
    /**
     * Generic error message to avoid exposing the
     * exact error position.
     */
    const ERR_INVALID_AUTH_PARAMS = "Invalid authentication credentials";

    /**
     * Number of seconds to allow for the client and server
     * time to deviate. This should be small, to prevent replay
     * attacks.
     */
    const AUTH_REPLAY_RANGE = 120;

    private $name;
    private $method;
    private $verb;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function isCallable($method, $verb)
    {
        if (!$verb) {
            return false;
        }
        else {
            $methodName = $this->getMethodName($method, $verb);
            return method_exists($this, $methodName);
        }
    }

    public function call($method, $verb, $requestArgs)
    {
        $this->method = $method;
        $this->verb = $verb;

        $methodName = $this->getMethodName($method, $verb);

        switch ($method) {
            case 'DELETE':
            case 'POST':
                return $this->$methodName($_POST, $requestArgs);

            case 'GET':
                return $this->$methodName($_GET, $requestArgs);

            case 'PUT':
                $fileHandle = new FileHandle(fopen("php://input", "r"));
                return $this->$methodName($_GET, $requestArgs, $fileHandle);

            default:
                throw new BadRequestHttpException("Invalid method " . $method);
        }
    }

    public static function getNamespace()
    {
        return __NAMESPACE__;
    }

    public static function getBaseDir()
    {
        return dirname(__FILE__);
    }

    private function getMethodName($method, $verb)
    {
        return strtolower($method) . strtoupper(substr($verb, 0, 1)) . substr($verb, 1);
    }

    protected function authenticate($securityContext, array $methodArgs, array $requestArgs)
    {
        $this->validateTimeRandAndSignature($methodArgs);

        $actualTime = $methodArgs['time'];
        $actualRandomValue = $methodArgs['rand'];
        $actualSignature = $methodArgs['signature'];

        unset($methodArgs['time']);
        unset($methodArgs['rand']);
        unset($methodArgs['signature']);

        $originalRequest = $this->getOriginalRequest($requestArgs);

        $protectedInput =
            $this->method
            . ":" . $originalRequest
            . ":" . http_build_query($methodArgs)
            . ":" . $actualTime
            . ":" . $actualRandomValue;

        $apiKey = $this->readApiKey($securityContext);
        $expectedSignature = hash_hmac("sha256", $protectedInput, $apiKey);

        if ($expectedSignature != $actualSignature) {
            throw new UnauthorizedHttpException(self::ERR_INVALID_AUTH_PARAMS);
        }
    }

    private function readApiKey($securityContext)
    {
        $keys = FileUtil::readPropertiesFile("keys", $securityContext);

        if (!isset($keys['key'])) {
            throw new ConfigException("Cannot read API key from configuration");
        }

        return $keys['key'];
    }

    private function validateTimeRandAndSignature($methodArgs)
    {
        if (!isset($methodArgs['signature']) || !isset($methodArgs['time']) || !isset($methodArgs['rand'])
            || !is_numeric($methodArgs['time'])) {

            throw new UnauthorizedHttpException(self::ERR_INVALID_AUTH_PARAMS);
        }

        $actualTime = intval($methodArgs['time']);
        $timeInAllowedRange = $actualTime > time() - 5*60 && $actualTime < time() + 5*60; // Repeat attacks

        if (!$timeInAllowedRange) {
            throw new UnauthorizedHttpException(self::ERR_INVALID_AUTH_PARAMS);
        }
    }

    private function getOriginalRequest(array $requestArgs)
    {
        $nameStr = $this->name;
        $verbStr = ($this->verb) ? "/" . $this->verb : "";
        $requestArgsStr = (count($requestArgs) > 0) ? "/" . join("/", $requestArgs) : "";

        return $nameStr . $verbStr . $requestArgsStr;
    }
}