<?php

// Задание 1. Требуется написать функцию, которая на вход принимает
// значения min, max, count, а на выход генерирует массив случайных
// не повторяющихся чисел от min до max размером count.


function limitedRandomGenerator($min, $max, $count): array
{
    $result = array();
    $i = 1;

    while ($i <= $count) {
        $newNumber = mt_rand($min, $max);
        if (!in_array($newNumber, $result)) {
            $result[] = mt_rand($min, $max);
            $i++;
        }
    }

    return $result;
}


var_dump(limitedRandomGenerator(1, 10, 5));

