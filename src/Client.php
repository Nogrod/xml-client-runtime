<?php

namespace Nogrod\XMLClientRuntime;

use GoetasWebservices\Xsd\XsdToPhpRuntime\Jms\Handler\BaseTypesHandler;
use GoetasWebservices\Xsd\XsdToPhpRuntime\Jms\Handler\XmlSchemaDateHandler;
use GuzzleHttp\Psr7\Utils;
use Http\Client\Exception\HttpException;
use Http\Discovery\Psr17Factory;
use Http\Discovery\Psr18ClientDiscovery;
use JMS\Serializer\Expression\ExpressionEvaluator;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use JMS\Serializer\Serializer;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializerInterface;
use JMS\Serializer\Visitor\Factory\JsonSerializationVisitorFactory;
use JMS\Serializer\Visitor\Factory\XmlDeserializationVisitorFactory;
use JMS\Serializer\Visitor\Factory\XmlSerializationVisitorFactory;
use Nogrod\XMLClientRuntime\Exception\ServerException;
use Nogrod\XMLClientRuntime\Exception\UnexpectedFormatException;
use Nogrod\XMLClientRuntime\Handler\JsonDateHandler;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sabre\Xml\Service;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Client
 */
abstract class Client
{
    /**
     * @var Serializer
     */
    protected SerializerInterface|Serializer $serializer;

    protected ?Service $sabre;

    protected ClientInterface $client;

    protected Psr17Factory $messageFactory;

    private RequestInterface $requestMessage;

    private ResponseInterface $responseMessage;

    private array $config;

    public function __construct(array $config = [], ?Serializer $serializer = null, ?Psr17Factory $messageFactory = null, ?ClientInterface $client = null)
    {
        $this->config = $config;
        $this->serializer = $serializer ?: self::createSerializer($this->getJmsMetaPath(), $this->getConfig('cacheDir'));
        $this->sabre = $this->getSabre();
        $this->client = $client ?: Psr18ClientDiscovery::find();
        $this->messageFactory = $messageFactory ?: new Psr17Factory();
    }

    /**
     * @param array    $jmsMetadata
     * @param string   $cacheDir
     * @param callable $callback
     *
     * @return SerializerInterface
     */
    private static function createSerializer(array $jmsMetadata, ?string $cacheDir = null, ?callable $callback = null): SerializerInterface
    {
        $serializerBuilder = SerializerBuilder::create();

        $serializerBuilder->setDebug(false);

        if (null !== $cacheDir) {
            $serializerBuilder->setCacheDir($cacheDir);
        }

        $serializerBuilder->setExpressionEvaluator(new ExpressionEvaluator(new ExpressionLanguage()));

        $serializerBuilder->setSerializationVisitor('json', new JsonSerializationVisitorFactory());
        $serializationVisitor = new XmlSerializationVisitorFactory();
        //$serializationVisitor->setFormatOutput(false);
        $serializerBuilder->setSerializationVisitor('xml', $serializationVisitor);
        $serializerBuilder->setDeserializationVisitor('xml', new XmlDeserializationVisitorFactory());

        $serializerBuilder->configureHandlers(function (HandlerRegistryInterface $handler) use ($callback, $serializerBuilder) {
            $serializerBuilder->addDefaultHandlers();
            $handler->registerSubscribingHandler(new BaseTypesHandler()); // XMLSchema List handling
            $handler->registerSubscribingHandler(new XmlSchemaDateHandler()); // XMLSchema date handling
            $handler->registerSubscribingHandler(new JsonDateHandler()); // XMLSchema date handling
            if ($callback) {
                call_user_func($callback, $handler);
            }
        });

        foreach ($jmsMetadata as $php => $dir) {
            $serializerBuilder->addMetadataDir($dir, $php);
        }
        return $serializerBuilder->build();
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
     * @param string $body
     * @param string $outClass
     * @param string $type
     *
     * @return mixed
     */
    public function deserialize(string $body, string $outClass, string $type = 'xml'): mixed
    {
        $outClass = ltrim($outClass, "\\");

        return $this->serializer->deserialize($body, $outClass, $type);
    }

    public function deserializeSabre(string $body): array|object|string
    {
        return $this->sabre->parse($body);
    }

    /**
     * @param string $message
     * @param string $type
     *
     * @return string
     */
    public function serialize(object $message, string $type = 'xml'): string
    {
        return $this->serializer->serialize($message, $type);
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
        $w->writeElement(self::getRootElementName($message), $message);

        return $this->closeSabreWriter($w);
    }

    /**
     * Returns the local class name of $message, which is what the root element is named after.
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
        return $this->deserialize((string) $response->getBody(), $outClass);
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

    protected function getJmsMetaPath(): array
    {
        return [];
    }

    protected abstract function getSabre(): Service;
}
