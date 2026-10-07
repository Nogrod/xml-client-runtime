<?php

namespace Nogrod\XMLClientRuntime;

use GuzzleHttp\Psr7\Utils;
use Http\Client\Exception\HttpException;
use Http\Discovery\Psr17Factory;
use Http\Discovery\Psr18ClientDiscovery;
use Nogrod\XMLClientRuntime\Exception\ServerException;
use Nogrod\XMLClientRuntime\Exception\UnexpectedFormatException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sabre\Xml\Service;

/**
 * Client
 *
 * Messages are written and read by the (de)serialization code generated into the types,
 * through sabre's Writer and Reader.
 */
abstract class Client
{
    protected ?Service $sabre;

    protected ClientInterface $client;

    protected Psr17Factory $messageFactory;

    private RequestInterface $requestMessage;

    private ResponseInterface $responseMessage;

    private array $config;

    /**
     * Global element name (Clark notation) per class, from the elementMap.
     *
     * @var array<class-string, string>|null
     */
    private ?array $rootElementNames = null;

    public function __construct(array $config = [], ?Psr17Factory $messageFactory = null, ?ClientInterface $client = null)
    {
        $this->config = $config;
        $this->sabre = $this->getSabre();
        $this->client = $client ?: Psr18ClientDiscovery::find();
        $this->messageFactory = $messageFactory ?: new Psr17Factory();
    }

    /**
     * @param $operation
     * @param $outClass
     * @param $message
     * @return mixed
     * @throws ServerException
     * @throws UnexpectedFormatException
     * @throws ClientExceptionInterface
     */
    public function call($operation, string $outClass, $message): mixed
    {
        $this->prepareMessage($operation, $message);
        $this->requestMessage = $request = $this->buildRequest($operation, $message);
        try {
            $this->responseMessage = $response = $this->client->sendRequest($request);
            if (strpos($response->getHeaderLine('Content-Type'), 'xml') === false) {
                throw new UnexpectedFormatException(
                    $response,
                    $request,
                    "Unexpected content type '" . $response->getHeaderLine('Content-Type') . "'"
                );
            }
            if ($response->getStatusCode() !== 200) {
                $this->handleResponseError($response, $request);
            }
            $response = $this->handleResponse($response, $outClass);
        } catch (HttpException $e) {
            throw new ServerException(
                $e->getResponse(),
                $e->getRequest(),
                $e
            );
        }

        return $response;
    }

    /**
     * @return RequestInterface|null
     */
    public function __getLastRequestMessage(): ?RequestInterface
    {
        return $this->requestMessage;
    }

    /**
     * @return ResponseInterface|null
     */
    public function __getLastResponseMessage(): ?ResponseInterface
    {
        return $this->responseMessage;
    }

    /**
     * @param $option
     * @return array|mixed|null
     */
    public function getConfig(?string $option = null): mixed
    {
        return $option === null ? $this->config : ($this->config[$option] ?? null);
    }

    public function setConfig(array $configuration): void
    {
        $this->config = array_merge($this->config, $configuration);
    }

    /**
     * Reads a document whose root element is a global element of type $outClass.
     *
     * @template T of object
     *
     * @param class-string<T> $outClass
     *
     * @return T
     *
     * @throws \UnexpectedValueException if the root element is not of type $outClass
     */
    public function deserialize(string $body, string $outClass, string $type = 'xml'): mixed
    {
        if ('xml' !== $type) {
            throw new \InvalidArgumentException(sprintf('Deserializing from "%s" is not supported.', $type));
        }
        $outClass = ltrim($outClass, '\\');
        $result = $this->sabre->parse($body, null, $rootElementName);
        if (!$result instanceof $outClass) {
            throw new \UnexpectedValueException(sprintf('Expected %s, got root element %s.', $outClass, $rootElementName));
        }

        return $result;
    }

    public function deserializeSabre(string $body): array|object|string
    {
        return $this->sabre->parse($body);
    }

    /**
     * Writes $message as XML document, or as JSON with the element and attribute names
     * as keys.
     */
    public function serialize(object $message, string $type = 'xml'): string
    {
        switch ($type) {
            case 'xml':
                return $this->serializeSabre($message, 'utf-8', false);
            case 'json':
                return json_encode($message, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        throw new \InvalidArgumentException(sprintf('Serializing to "%s" is not supported.', $type));
    }

    /**
     * @param string $message
     * @param string $type
     *
     * @return string
     */
    public function serializeSabre(object $message, string $encoding = 'utf-8', bool $indent = true): ?string
    {
        return $this->serializeSabreInternal($message, null, $encoding, $indent);
    }

    /**
     * @param string $message
     * @param string $type
     *
     * @return string
     */
    public function serializeSabreFile(object $message, string $file, string $encoding = 'utf-8', bool $indent = true): ?string
    {
        return $this->serializeSabreInternal($message, $file, $encoding, $indent);
    }

    /**
     * @param string $message
     * @param string $type
     *
     * @return string
     */
    public function serializeSabreInternal(object $message, ?string $file = null, string $encoding = 'utf-8', bool $indent = true): ?string
    {
        $w = $this->openSabreWriter($file, $encoding, $indent);
        $w->writeElement($this->rootElementName($message), $message);

        return $this->closeSabreWriter($w);
    }

    /**
     * Returns the name of the global element $message is written as: the one the
     * elementMap maps its class to, or else its local class name.
     *
     * The elementMap matters where element and class names differ, as with
     * getVersionRequest and GetVersionRequest.
     */
    public function rootElementName(object $message): string
    {
        if (null === $this->rootElementNames) {
            $this->rootElementNames = [];
            foreach ($this->sabre->elementMap as $element => $class) {
                if (is_string($class)) {
                    $this->rootElementNames[ltrim($class, '\\')] ??= $element;
                }
            }
        }

        return $this->rootElementNames[$message::class] ?? self::getRootElementName($message);
    }

    /**
     * Returns the local class name of $message.
     */
    public static function getRootElementName(object $message): string
    {
        $classname = get_class($message);
        $pos = strrpos($classname, '\\');

        return $pos === false ? $classname : mb_substr($classname, $pos + 1);
    }

    /**
     * Opens a writer for building a document element by element.
     *
     * The document is started but no root element is written: the caller opens and
     * closes elements itself and must finish through closeSabreWriter(). Use this
     * when a document holds an unbounded number of child elements that should not
     * all be built in memory first.
     *
     * @param string|null $file target path, or null to build the document in memory
     */
    public function openSabreWriter(?string $file = null, string $encoding = 'utf-8', bool $indent = true): Writer
    {
        // Same setup as Service::getWriter(), but on a Writer that can suppress the
        // automatic xmlns declarations without reflection.
        $w = new Writer();
        $w->namespaceMap = $this->sabre->namespaceMap;
        $w->classMap = $this->sabre->classMap;
        $w->markNamespacesWritten();
        $w->inMemory = $file === null;
        if ($w->inMemory) {
            $w->openMemory();
        } else {
            $w->openUri($file);
        }
        $w->contextUri = null;
        $w->setIndent($indent);
        $w->startDocument('1.0', $encoding);

        return $w;
    }

    /**
     * Finishes a writer opened by openSabreWriter().
     *
     * @return string|null the document when the writer was opened in memory mode, null when writing to a file
     */
    public function closeSabreWriter(Writer $writer): ?string
    {
        $writer->endDocument();
        if ($writer->inMemory) {
            // Careful: flush() empties the buffer and returns it, so calling it
            // before outputMemory() would leave nothing to read.
            return $writer->outputMemory();
        }

        $writer->flush();

        return null;
    }

    protected function getUrl(): ?string
    {
        return null;
    }

    protected function handleResponseError(ResponseInterface $response, RequestInterface|MessageInterface $request): void
    {
        //serialize ErrorMessage class
        throw new UnexpectedFormatException($response, $request, $request->getBody().PHP_EOL.$response->getBody());
    }

    protected function handleResponse(ResponseInterface $response, string $outClass): mixed
    {
        try {
            return $this->deserialize((string) $response->getBody(), $outClass);
        } catch (\UnexpectedValueException $e) {
            throw new UnexpectedFormatException($response, $this->requestMessage, $e->getMessage());
        }
    }

    protected function prepareMessage(string $operation, object $message): object
    {
        return $message;
    }

    protected function buildRequest(string $operation, object $message): RequestInterface|MessageInterface
    {
        $psrRequest = $this->messageFactory->createRequest('POST', $this->getUrl());

        return $this->withHeaders($psrRequest, $this->buildHeaders($operation))->withBody(
            Utils::streamFor($this->serialize($message))
        );
    }

    protected function withHeaders(RequestInterface $request, array $headers): RequestInterface|MessageInterface
    {
        foreach ($headers as $key => $value) {
            $request = $request->withHeader($key, $value);
        }

        return $request;
    }

    protected function buildHeaders(string $operation): array
    {
        return [
            'Content-Type' => 'text/xml; charset=utf-8',
        ];
    }

    protected abstract function getSabre(): Service;
}
