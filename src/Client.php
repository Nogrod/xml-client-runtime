<?php

namespace Nogrod\XMLClientRuntime;

use GoetasWebservices\Xsd\XsdToPhpRuntime\Jms\Handler\BaseTypesHandler;
use GoetasWebservices\Xsd\XsdToPhpRuntime\Jms\Handler\XmlSchemaDateHandler;
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
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class Client
{
    /**
     * @var Serializer
     */
    protected $serializer;

    /** @var \Sabre\Xml\Service */
    protected $sabre;

    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var Psr17Factory
     */
    protected $messageFactory;

    /**
     * @var RequestInterface
     */
    private $requestMessage;

    /**
     * @var ResponseInterface
     */
    private $responseMessage;

    private $config;

    public function __construct(array $config = [], Serializer $serializer = null, Psr17Factory $messageFactory = null, ClientInterface $client = null)
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
    private static function createSerializer(array $jmsMetadata, string $cacheDir = null, callable $callback = null)
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

    public function call($operation, $outClass, $message)
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
    public function __getLastRequestMessage()
    {
        return $this->requestMessage;
    }

    /**
     * @return ResponseInterface|null
     */
    public function __getLastResponseMessage()
    {
        return $this->responseMessage;
    }

    public function getConfig($option = null)
    {
        return $option === null ? $this->config : (isset($this->config[$option]) ? $this->config[$option] : null);
    }

    public function setConfig(array $configuration)
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
    public function deserialize($body, $outClass, $type = 'xml')
    {
        $outClass = ltrim($outClass, "\\");

        return $this->serializer->deserialize($body, $outClass, $type);
    }

    public function deserializeSabre($body)
    {
        return $this->sabre->parse($body);
    }

    /**
     * @param string $message
     * @param string $type
     *
     * @return string
     */
    public function serialize($message, $type = 'xml')
    {
        return $this->serializer->serialize($message, $type);
    }

    /**
     * @param string $message
     * @param string $type
     *
     * @return string
     */
    public function serializeSabre($message, $encoding = 'utf-8', $indent = true)
    {
        $classname = get_class($message);
        $classname = mb_substr($classname, strrpos($classname, '\\') + 1);

        //return $this->sabre->write($classname, $message);
        $w = $this->sabre->getWriter();
        \Closure::fromCallable(function () { $this->namespacesWritten = true; })->call($w);
        $w->openMemory();
        $w->contextUri = null;
        $w->setIndent($indent);
        $w->startDocument('1.0', $encoding);
        $w->writeElement($classname, $message);

        return $w->outputMemory();
    }

    protected function getUrl()
    {
        return null;
    }

    protected function handleResponseError($response, $request)
    {
        //serialize ErrorMessage class
        throw new UnexpectedFormatException($response, $request, $request->getBody().PHP_EOL.$response->getBody());
    }

    protected function handleResponse($response, $outClass)
    {
        return $this->deserialize((string) $response->getBody(), $outClass);
    }

    protected function prepareMessage($operation, $message)
    {
        return $message;
    }

    protected function buildRequest($operation, $message)
    {
        return $this->messageFactory->createRequest('POST', $this->getUrl(), $this->buildHeaders($operation), $this->serialize($message));
    }

    protected function buildHeaders(string $operation)
    {
        return [
            'Content-Type' => 'text/xml; charset=utf-8',
        ];
    }

    protected function getJmsMetaPath()
    {
        return [];
    }

    protected function getSabre()
    {
        return null;
    }
}
