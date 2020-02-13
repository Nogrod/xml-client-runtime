<?php

namespace Nogrod\XMLClientRuntime\Handler;

use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\GraphNavigatorInterface;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\Visitor\DeserializationVisitorInterface;
use JMS\Serializer\Visitor\SerializationVisitorInterface;

class JsonDateHandler implements SubscribingHandlerInterface
{
    /**
     * @var string
     */
    private $defaultFormat;

    /**
     * @var \DateTimeZone
     */
    private $defaultTimezone;

    /**
     * @var bool
     */
    private $xmlCData;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribingMethods()
    {
        $methods = [
            [
                'type' => 'GoetasWebservices\Xsd\XsdToPhp\XMLSchema\DateTime',
                'direction' => GraphNavigatorInterface::DIRECTION_DESERIALIZATION,
                'format' => 'json',
            ],
            [
                'type' => 'GoetasWebservices\Xsd\XsdToPhp\XMLSchema\Date',
                'direction' => GraphNavigatorInterface::DIRECTION_DESERIALIZATION,
                'format' => 'json',
            ],
            [
                'type' => 'GoetasWebservices\Xsd\XsdToPhp\XMLSchema\Time',
                'direction' => GraphNavigatorInterface::DIRECTION_DESERIALIZATION,
                'format' => 'json',
            ],
            [
                'type' => 'DateInterval',
                'direction' => GraphNavigatorInterface::DIRECTION_DESERIALIZATION,
                'format' => 'json',
            ],
            [
                'type' => 'GoetasWebservices\Xsd\XsdToPhp\XMLSchema\DateTime',
                'format' => 'json',
                'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
                'method' => 'serializeDateTime',
            ],
            [
                'type' => 'GoetasWebservices\Xsd\XsdToPhp\XMLSchema\Date',
                'format' => 'json',
                'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
                'method' => 'serializeDateTime',
            ],
            [
                'type' => 'GoetasWebservices\Xsd\XsdToPhp\XMLSchema\Time',
                'format' => 'json',
                'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
                'method' => 'serializeDateTime',
            ],
            [
                'type' => 'DateInterval',
                'format' => 'json',
                'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
                'method' => 'serializeDateInterval',
            ],
        ];

        return $methods;
    }

    public function __construct(string $defaultFormat = \DateTime::ATOM, string $defaultTimezone = 'UTC', bool $xmlCData = true)
    {
        $this->defaultFormat = $defaultFormat;
        $this->defaultTimezone = new \DateTimeZone($defaultTimezone);
        $this->xmlCData = $xmlCData;
    }

    /**
     * @return \DOMCdataSection|\DOMText|mixed
     */
    private function serializeDateTimeInterface(
        SerializationVisitorInterface $visitor,
        \DateTimeInterface $date,
        array $type,
        SerializationContext $context
    ) {
        $format = $this->getFormat($type);
        if ('U' === $format) {
            return $visitor->visitInteger((int) $date->format($format), $type);
        }

        return $visitor->visitString($date->format($this->getFormat($type)), $type);
    }

    /**
     * @param array $type
     *
     * @return \DOMCdataSection|\DOMText|mixed
     */
    public function serializeDateTime(SerializationVisitorInterface $visitor, \DateTime $date, array $type, SerializationContext $context)
    {
        return $this->serializeDateTimeInterface($visitor, $date, $type, $context);
    }

    /**
     * @param array $type
     *
     * @return \DOMCdataSection|\DOMText|mixed
     */
    public function serializeDateInterval(SerializationVisitorInterface $visitor, \DateInterval $date, array $type, SerializationContext $context)
    {
        $iso8601DateIntervalString = $this->format($date);

        return $visitor->visitString($iso8601DateIntervalString, $type);
    }

    /**
     * @param mixed $data
     * @param array $type
     */
    public function deserializeDateTimeFromJson(DeserializationVisitorInterface $visitor, $data, array $type): ?\DateTimeInterface
    {
        if (null === $data) {
            return null;
        }

        return $this->parseDateTime($data, $type);
    }

    /**
     * @param mixed $data
     * @param array $type
     */
    public function deserializeDateIntervalFromJson(DeserializationVisitorInterface $visitor, $data, array $type): ?\DateInterval
    {
        if (null === $data) {
            return null;
        }

        return $this->parseDateInterval($data);
    }

    /**
     * @param mixed $data
     * @param array $type
     */
    private function parseDateTime($data, array $type): \DateTimeInterface
    {
        $timezone = !empty($type['params'][1]) ? new \DateTimeZone($type['params'][1]) : $this->defaultTimezone;
        $format = $this->getDeserializationFormat($type);

        $datetime = \DateTime::createFromFormat($format, (string) $data, $timezone);

        if (false === $datetime) {
            throw new RuntimeException(sprintf('Invalid datetime "%s", expected format %s.', $data, $format));
        }

        if ('U' === $format) {
            $datetime = $datetime->setTimezone($timezone);
        }

        return $datetime;
    }

    private function parseDateInterval(string $data): \DateInterval
    {
        $dateInterval = null;
        try {
            $dateInterval = new \DateInterval($data);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Invalid dateinterval "%s", expected ISO 8601 format', $data), null, $e);
        }

        return $dateInterval;
    }

    /**
     * @param array $type
     */
    private function getDeserializationFormat(array $type): string
    {
        if (isset($type['params'][2])) {
            return $type['params'][2];
        }
        if (isset($type['params'][0])) {
            return $type['params'][0];
        }
        return $this->defaultFormat;
    }

    /**
     * @param array $type
     */
    private function getFormat(array $type): string
    {
        return $type['params'][0] ?? $this->defaultFormat;
    }

    public function format(\DateInterval $dateInterval): string
    {
        $format = 'P';

        if (0 < $dateInterval->y) {
            $format .= $dateInterval->y . 'Y';
        }

        if (0 < $dateInterval->m) {
            $format .= $dateInterval->m . 'M';
        }

        if (0 < $dateInterval->d) {
            $format .= $dateInterval->d . 'D';
        }

        if (0 < $dateInterval->h || 0 < $dateInterval->i || 0 < $dateInterval->s) {
            $format .= 'T';
        }

        if (0 < $dateInterval->h) {
            $format .= $dateInterval->h . 'H';
        }

        if (0 < $dateInterval->i) {
            $format .= $dateInterval->i . 'M';
        }

        if (0 < $dateInterval->s) {
            $format .= $dateInterval->s . 'S';
        }

        if ('P' === $format) {
            $format = 'P0DT0S';
        }

        return $format;
    }
}
