<?php


namespace Nogrod\XMLClientRuntime;

class Func
{
    public static function mapArray(array &$array, string $name)
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
                foreach ($tmpAttr as $attrKey => $attrValue) {
                    $tmpValue[] = ['name' => $attrKey, 'value' => $attrValue, 'attributes' => []];
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
