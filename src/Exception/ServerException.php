<?php

namespace Nogrod\XMLClientRuntime\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ServerException extends \Exception
{
    private RequestInterface $request;
    private ResponseInterface $response;

    public function __construct(ResponseInterface $response, RequestInterface $request, \Exception $previous = null)
    {
        parent::__construct("Server error", null, $previous);
        $this->response = $response;
        $this->request = $request;
    }

    /**
     * @return RequestInterface
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
