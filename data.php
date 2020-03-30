<?php
/**
 *
 * As part of athari/yalinqo-perf, licensed under the following terms:
 *
 *            DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
 *                    Version 2, December 2004
 *
 * Copyright (C) 2014 Alexander Prokhorov
 *
 * Everyone is permitted to copy and distribute verbatim or modified
 * copies of this license document, and changing it is allowed as long
 * as the name is changed.
 *
 *            DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
 *   TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION
 *
 *  0. You just DO WHAT THE FUCK YOU WANT TO.
 *
 */

declare(strict_types=1);

class SampleData
{
    public $categories;
    public $products;
    public $users;
    public $orders;
    public $strings;

    public function __construct($n)
    {
        if ($n < 10) {
            throw new InvalidArgumentException('n must be 10 or larger');
        }
        $this->categories = $this->generateProductCategories($n / 10);
        $this->products = $this->generateProducts($n);
        $this->users = $this->generateUsers($n / 10);
        $this->orders = $this->generateOrders($n);
        $this->strings = $this->generateStrings($n);
    }

    private function generateProductCategories($n)
    {
        $categories = [];
        for ($i = 1; $i <= $n; ++$i) {
            $categories[] = [
                'id' => $i,
                'name' => $this->randomString('category'),
                'desc' => $this->randomString('category-desc').$this->randomString(),
            ];
        }

        return $categories;
    }

    private function generateProducts($n)
    {
        $products = [];
        for ($i = 1; $i <= $n; ++$i) {
            $products[] = [
                'id' => $i,
                'name' => $this->randomString('product'),
                'catId' => \array_rand($this->categories)['id'],
                'quantity' => \random_int(1, 100),
            ];
        }

        return $products;
    }

    private function generateUsers($n)
    {
        $users = [];
        for ($i = 1; $i <= $n; ++$i) {
            $users[] = [
                'id' => $i,
                'name' => $this->randomString('user'),
                'rating' => \random_int(0, 10),
            ];
        }

        return $users;
    }

    private function generateOrders($n)
    {
        $orders = [];
        for ($i = 1; $i <= $n; ++$i) {
            $orders[] = [
                'id' => $i,
                'customerId' => \array_rand($this->users)['id'],
                'items' => $this->generateOrderItems(\random_int(1, 10)),
            ];
        }

        return $orders;
    }

    private function generateOrderItems($n)
    {
        $items = [];
        for ($i = 1; $i <= $n; ++$i) {
            $items[] = [
                'prodId' => \array_rand($this->products)['id'],
                'quantity' => \random_int(0, 10),
            ];
        }

        return $items;
    }

    private function generateStrings($n)
    {
        $strings = [];
        for ($i = 1; $i <= $n; ++$i) {
            $strings[] = $this->randomString('s');
        }

        return $strings;
    }

    private function randomString($prefix = '')
    {
        return \uniqid("{$prefix}-", true);
    }
}
