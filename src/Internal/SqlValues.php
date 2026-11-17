<?php

/*
 * Converts values to and from the text PostgreSQL sends and receives for them, for queries with parameters.
 *
 * @internal
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Internal;


final readonly class SqlValues
{
    /**
     * Convert a time to a timestamptz, keeping its microseconds and offset.
     * @param \DateTimeInterface $time
     * @return string
     */
    public static function formatTimestamp(\DateTimeInterface $time) : string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }


    /**
     * Convert strings to a text[] literal, e.g. {"a","b \"c\""}.
     * @param list<string> $values
     * @return string
     */
    public static function formatTextArray(array $values) : string
    {
        $quoted = [];

        foreach ($values as $value)
        {
            $quoted[] = '"' . addcslashes($value, '"\\') . '"';
        }

        return '{' . implode(',', $quoted) . '}';
    }


    /**
     * Convert ints to an int[] or bigint[] literal, e.g. {60,300}.
     * @param list<int> $values
     * @return string
     */
    public static function formatIntArray(array $values) : string
    {
        return '{' . implode(',', $values) . '}';
    }


    /**
     * Convert an int[] that PostgreSQL returned, e.g. {60,300}, to ints.
     * @param string $literal
     * @return list<int>
     */
    public static function parseIntArray(string $literal) : array
    {
        $inside = trim($literal, '{}');
        $values = [];

        if ($inside !== '')
        {
            foreach (explode(',', $inside) as $value)
            {
                $values[] = (int)$value;
            }
        }

        return $values;
    }
}
