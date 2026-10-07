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
