<?php


namespace Nogrod\XMLClientRuntime;

class Func
{
    /**
     * Declares the default namespace of a generated type on the element it is written into.
     *
     * Writer only declares it where it is not already in scope; any other sabre writer
     * gets the declaration on every element, as before.
     */
    public static function writeDefaultNamespace(\Sabre\Xml\Writer $writer, string $namespace): void
    {
        if ($writer instanceof Writer) {
            $writer->writeDefaultNamespace($namespace);

            return;
        }

        $writer->writeAttribute('xmlns', $namespace);
    }

    /**
     * Declares the namespace of a global element (an API request or response) on the
     * element it is written into, even when the parent already declares it.
     *
     * Any other sabre writer already gets the declaration from writeDefaultNamespace()
     * on every element, so nothing is written here to avoid a duplicate attribute.
     */
    public static function writeRootNamespace(\Sabre\Xml\Writer $writer, string $namespace): void
    {
        if ($writer instanceof Writer) {
            $writer->writeRootNamespace($namespace);
        }
    }

    /**
     * Formats an xs:dateTime in UTC.
     *
     * The value is converted to UTC first: the Z suffix states UTC, so writing the local
     * time with it would shift the timestamp by the zone offset.
     */
    public static function formatDateTime(mixed $value): string
    {
        if (!$value instanceof \DateTimeInterface) {
            return (string) $value;
        }
        static $utc;
        $utc ??= new \DateTimeZone('UTC');

        return \DateTimeImmutable::createFromInterface($value)->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Formats an xs:date.
     */
    public static function formatDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }

    /**
     * Formats an xs:time, with the zone offset unless it is UTC.
     */
    public static function formatTime(mixed $value): string
    {
        if (!$value instanceof \DateTimeInterface) {
            return (string) $value;
        }

        return 0 === $value->getOffset() ? $value->format('H:i:s') : $value->format('H:i:sP');
    }

    /**
     * Reads the element the reader is positioned on into $object and moves past its end.
     *
     * Attributes and child elements are handed to the generated xmlReadAttribute() and
     * xmlReadElement() of $object; children it does not know are skipped.
     *
     * @template T of object
     *
     * @param T $object
     *
     * @return T
     */
    public static function readObject(\XMLReader $reader, object $object): object
    {
        if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
                $object->xmlReadAttribute($reader);
            }
            $reader->moveToElement();
        }
        if ($reader->isEmptyElement) {
            $reader->next();

            return $object;
        }
        $reader->read();
        while (true) {
            switch ($reader->nodeType) {
                case \XMLReader::ELEMENT:
                    if (!$object->xmlReadElement($reader)) {
                        $reader->next();
                    }
                    break;
                case \XMLReader::END_ELEMENT:
                    $reader->read();

                    return $object;
                default:
                    if (!$reader->read()) {
                        return $object;
                    }
            }
        }
    }

    /**
     * Reads the attributes of the element the reader is positioned on into $object and
     * returns its text content, moving past the end of the element.
     *
     * For types with simple content: a value plus attributes.
     */
    public static function readValue(\XMLReader $reader, object $object): string
    {
        if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
                $object->xmlReadAttribute($reader);
            }
            $reader->moveToElement();
        }

        return self::readText($reader);
    }

    /**
     * Returns the text content of the element the reader is positioned on and moves past
     * its end.
     */
    public static function readText(\XMLReader $reader): string
    {
        $text = $reader->readString();
        $reader->next();

        return $text;
    }

    /**
     * Reads the entries of a wrapped list: every $entry child of the element the reader
     * is positioned on, each read by $read. Entries $read returns null for are left out.
     */
    public static function readList(\XMLReader $reader, string $entry, string $namespace, \Closure $read): array
    {
        $list = [];
        if ($reader->isEmptyElement) {
            $reader->next();

            return $list;
        }
        $reader->read();
        while (true) {
            switch ($reader->nodeType) {
                case \XMLReader::ELEMENT:
                    if ($entry === $reader->localName && $namespace === $reader->namespaceURI) {
                        $value = $read($reader);
                        if (null !== $value) {
                            $list[] = $value;
                        }
                    } else {
                        $reader->next();
                    }
                    break;
                case \XMLReader::END_ELEMENT:
                    $reader->read();

                    return $list;
                default:
                    if (!$reader->read()) {
                        return $list;
                    }
            }
        }
    }

    public static function mapArray(array &$array, string $name, bool $isValue = false)
    {
        $result = [];
        foreach ($array as $key => $value) {
            if ($value['name'] !== $name) {
                continue;
            }
            $tmpValue = $value['value'];
            $tmpAttr = $value['attributes'];
            unset($array[$key]);
            if ($tmpValue !== null) {
                if (!$isValue) {
                    foreach ($tmpAttr as $attrKey => $attrValue) {
                        $tmpValue[] = ['name' => $attrKey, 'value' => $attrValue, 'attributes' => []];
                    }
                }
                $result[] = $tmpValue;
            }
        }

        return $result;
    }

    public static function mapObject(array &$array, string $name)
    {
        foreach ($array as $key => $value) {
            if ($value['name'] !== $name) {
                continue;
            }
            if (is_array($value['value'])) {
                $tmpValue = $value['value'];
            } else {
                $tmpValue = [['name' => 'value', 'value' => $value['value'], 'attributes' => []]];
            }
            foreach ($value['attributes'] as $attrKey => $attrValue) {
                $tmpValue[] = ['name' => $attrKey, 'value' => $attrValue, 'attributes' => []];
            }
            unset($array[$key]);

            return $tmpValue;
        }

        return null;
    }

    public static function mapValue(array &$array, string $name)
    {
        foreach ($array as $key => $value) {
            if ($value['name'] !== $name) {
                continue;
            }
            $tmpValue = $value['value'];
            unset($array[$key]);
            return $tmpValue;
        }

        return null;
    }
}
