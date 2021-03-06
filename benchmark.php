<?php

require_once 'vendor/autoload.php';
require_once 'data.php';

use YaLinqo\Enumerable as E;
use Ginq\Ginq as G;
use Pinq\Traversable as P;
use YaLinqo\Functions as F;

function is_cli ()
{
    // PROMPT var is set by cmd, but not by PHPStorm.
    return php_sapi_name() == 'cli' && isset($_SERVER['PROMPT']);
}

function benchmark_operation ($count, $consume, $operation)
{
    $time = microtime(true);
    $message = "Success";
    $return = null;
    try {
        for ($i = 0; $i < $count; $i++) {
            $return = $operation();
            if ($consume !== null)
                $consume($return);
        }
        $return = $operation();
        if (!is_scalar($return))
            $return = E::from($return)->toListDeep();
        $result = true;
    }
    catch (Exception $e) {
        $result = false;
        $message = $e->getMessage();
    }
    return [
        'result' => $result,
        'return' => $return,
        'message' => $message,
        'time' => (microtime(true) - $time) / $count,
    ];
}

function benchmark_array ($name, $count, $consume, $benches)
{
    $benches = E::from($benches)->select('[ "name" => $k, "op" => $v ]')->toArray();

    // Run benchmarks
    echo "\n$name ";
    foreach ($benches as $k => $bench) {
        $benches[$k] = array_merge($bench, benchmark_operation($count, $consume, $bench['op']));
        echo ".";
    }
    // Remove progress dots with backspaces
    if (is_cli())
        echo str_repeat(chr(8), count($benches) + 1) . str_repeat(' ', count($benches) + 1);

    // Validate results
    $results = E::from($benches)->select(function ($b) {
        return [
            "name" => $b["name"],
            "return" => $b["result"] ? json_encode($b["return"], JSON_PRETTY_PRINT) : null
        ];
    })->toList();
    $return = $results[0]['return'];
    for ($i = 1; $i < count($results); $i++) {
        if ($results[$i]['return'] === null)
            continue;
        $returnOther = $results[$i]['return'];
        if ($return !== $returnOther) {
            echo "\nERROR: Results from tests '{$results[0]['name']}' and '{$results[$i]['name']}' do not match.\n";
            echo "0: {$return}\n{$i}: {$returnOther}\n";
            file_put_contents('tmp/result-0.txt', $return);
            file_put_contents('tmp/result-1.txt', $returnOther);
            die;
        }
    }

    // Draw table
    echo "\n" . str_repeat("-", strlen($name)) . "\n";
    $min = E::from($benches)->where('$v["result"]')->min('$v["time"]');
    if ($min == 0)
        $min = 0.0001;
    foreach ($benches as $bench)
        echo "  " . str_pad($bench['name'], 28) .
            ($bench['result']
                ? number_format($bench['time'], 5) . " sec   "
                . "x" . number_format($bench['time'] / $min, 1) . " "
                . ($bench['time'] != $min
                    ? "(+" . number_format(($bench['time'] / $min - 1) * 100, 0, ".", "") . "%)"
                    : "(100%)")
                : "* " . $bench['message'])
            . "\n";
}

function benchmark_linq_groups ($name, $count, $consume, $opsPhp, $opsYaLinqo, $opsGinq, $opsPinq)
{
    $benches = E::from([
        "PHP    " => $opsPhp,
        "YaLinqo" => $opsYaLinqo,
        "Ginq   " => $opsGinq,
        "Pinq   " => $opsPinq,
    ])->selectMany(
        '$ops ==> $ops',
        '$op ==> $op',
        '($op, $name, $detail) ==> is_numeric($detail) ? $name : "$name [$detail]"'
    );
    benchmark_array($name, $count, $consume, $benches);
}

function not_implemented ()
{
    throw new Exception("Not implemented");
}

function consume ($array, $props = null)
{
    foreach ($array as $k => $v)
        if ($props !== null)
            foreach ($props as $prop => $subprops)
                consume($v[$prop], $subprops);
}

//------------------------------------------------------------------------------

$ITER_MAX = isset($_SERVER['argv'][1]) ? (int)$_SERVER['argv'][1] : 100;
$DATA = new SampleData($ITER_MAX);

benchmark_linq_groups("Iterating over $ITER_MAX ints", 100, null,
    [
        "for" => function () use ($ITER_MAX) {
            $j = null;
            for ($i = 0; $i < $ITER_MAX; $i++)
                $j = $i;
            return $j;
        },
        "array functions" => function () use ($ITER_MAX) {
            $j = null;
            foreach (range(0, $ITER_MAX - 1) as $i)
                $j = $i;
            return $j;
        },
    ],
    [
        function () use ($ITER_MAX) {
            $j = null;
            foreach (E::range(0, $ITER_MAX) as $i)
                $j = $i;
            return $j;
        },
    ],
    [
        function () use ($ITER_MAX) {
            $j = null;
            foreach (G::range(0, $ITER_MAX - 1) as $i)
                $j = $i;
            return $j;
        },
    ],
    [
        function () use ($ITER_MAX) {
            $j = null;
            foreach (P::from(range(0, $ITER_MAX - 1)) as $i)
                $j = $i;
            return $j;
        },
    ]);

benchmark_linq_groups("Generating array of $ITER_MAX integers", 100, 'consume',
    [
        "for" =>
            function () use ($ITER_MAX) {
                $a = [ ];
                for ($i = 0; $i < $ITER_MAX; $i++)
                    $a[] = $i;
                return $a;
            },
        "array functions" =>
            function () use ($ITER_MAX) {
                return range(0, $ITER_MAX - 1);
            },
    ],
    [
        function () use ($ITER_MAX) {
            return E::range(0, $ITER_MAX)->toArray();
        },
    ],
    [
        function () use ($ITER_MAX) {
            return G::range(0, $ITER_MAX - 1)->toArray();
        },
    ],
    [
        function () use ($ITER_MAX) {
            return P::from(range(0, $ITER_MAX - 1))->asArray();
        },
    ]);

benchmark_linq_groups("Generating lookup of $ITER_MAX floats, calculate sum", 100, null,
    [
        function () use ($ITER_MAX) {
            $dic = [ ];
            for ($i = 0; $i < $ITER_MAX; $i++)
                $dic[(string)tan($i % 100)][] = $i % 2 ? sin($i) : cos($i);
            $sum = 0;
            foreach ($dic as $g)
                foreach ($g as $v)
                    $sum += $v;
            return $sum;
        },
    ],
    [
        function () use ($ITER_MAX) {
            $dic = E::range(0, $ITER_MAX)->toLookup(
                function ($v) { return (string)tan($v % 100); },
                function ($v) { return $v % 2 ? sin($v) : cos($v); });
            return E::from($dic)->selectMany(F::$value)->sum();
        },
        "string lambda" => function () use ($ITER_MAX) {
            $dic = E::range(0, $ITER_MAX)->toLookup('(string)tan($v % 100)', '$v % 2 ? sin($v) : cos($v)');
            return E::from($dic)->selectMany('$v')->sum();
        },
    ],
    [
        function () use ($ITER_MAX) {
            $dic = G::range(0, $ITER_MAX - 1)->toLookup(
                function ($v) { return (string)tan($v % 100); },
                function ($v) { return $v % 2 ? sin($v) : cos($v); });
            return G::from($dic)->selectMany(F::$value, F::$key)->sum();
        },
    ],
    [
        function () use ($ITER_MAX) {
            not_implemented();
        },
    ]);

benchmark_linq_groups("Counting values in arrays", 100, null,
    [
        "for" => function () use ($DATA) {
            $numberOrders = 0;
            foreach ($DATA->orders as $order) {
                if (count($order['items']) > 5)
                    $numberOrders++;
            }
            return $numberOrders;
        },
        "array functions" => function () use ($DATA) {
            return count(
                array_filter(
                    $DATA->orders,
                    function ($order) { return count($order['items']) > 5; }
                )
            );
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->orders)
                ->count(function ($order) { return count($order['items']) > 5; });
        },
        "string lambda" => function () use ($DATA) {
            return E::from($DATA->orders)
                ->count('$o ==> count($o["items"]) > 5');
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->orders)
                ->count(function ($order) { return count($order['items']) > 5; });
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->orders)
                ->where(function ($order) { return count($order['items']) > 5; })
                ->count();
        },
    ]);

benchmark_linq_groups("Counting values in arrays deep", 100, null,
    [
        "for" => function () use ($DATA) {
            $numberOrders = 0;
            foreach ($DATA->orders as $order) {
                $numberItems = 0;
                foreach ($order['items'] as $item) {
                    if ($item['quantity'] > 5)
                        $numberItems++;
                }
                if ($numberItems > 2)
                    $numberOrders++;
            }
            return $numberOrders;
        },
        "array functions" => function () use ($DATA) {
            return count(
                array_filter(
                    $DATA->orders,
                    function ($order) {
                        return count(
                            array_filter(
                                $order['items'],
                                function ($item) { return $item['quantity'] > 5; }
                            )
                        ) > 2;
                    })
            );
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->orders)
                ->count(function ($order) {
                    return E::from($order['items'])
                        ->count(function ($item) { return $item['quantity'] > 5; }) > 2;
                });
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->orders)
                ->count(function ($order) {
                    return G::from($order['items'])
                        ->count(function ($item) { return $item['quantity'] > 5; }) > 2;
                });
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->orders)
                ->where(function ($order) {
                    return P::from($order['items'])
                        ->where(function ($item) { return $item['quantity'] > 5; })
                        ->count() > 2;
                })
                ->count();
        },
    ]);

benchmark_linq_groups("Filtering values in arrays", 100, 'consume',
    [
        "for" => function () use ($DATA) {
            $filteredOrders = [ ];
            foreach ($DATA->orders as $order) {
                if (count($order['items']) > 5)
                    $filteredOrders[] = $order;
            }
            return $filteredOrders;
        },
        "array functions" => function () use ($DATA) {
            return array_filter(
                $DATA->orders,
                function ($order) { return count($order['items']) > 5; }
            );
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->orders)
                ->where(function ($order) { return count($order['items']) > 5; });
        },
        "string lambda" => function () use ($DATA) {
            return E::from($DATA->orders)
                ->where('$order ==> count($order["items"]) > 5');
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->orders)
                ->where(function ($order) { return count($order['items']) > 5; });
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->orders)
                ->where(function ($order) { return count($order['items']) > 5; });
        },
    ]);

benchmark_linq_groups("Filtering values in arrays deep", 100,
    function ($e) { consume($e, [ 'items' => null ]); },
    [
        "for" => function () use ($DATA) {
            $filteredOrders = [ ];
            foreach ($DATA->orders as $order) {
                $filteredItems = [ ];
                foreach ($order['items'] as $item) {
                    if ($item['quantity'] > 5)
                        $filteredItems[] = $item;
                }
                if (count($filteredItems) > 0) {
                    $order['items'] = $filteredItems;
                    $filteredOrders[] = [
                        'id' => $order['id'],
                        'items' => $filteredItems,
                    ];
                }
            }
            return $filteredOrders;
        },
        "array functions" => function () use ($DATA) {
            return array_filter(
                array_map(
                    function ($order) {
                        return [
                            'id' => $order['id'],
                            'items' => array_filter(
                                $order['items'],
                                function ($item) { return $item['quantity'] > 5; }
                            )
                        ];
                    },
                    $DATA->orders
                ),
                function ($order) {
                    return count($order['items']) > 0;
                }
            );
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->orders)
                ->select(function ($order) {
                    return [
                        'id' => $order['id'],
                        'items' => E::from($order['items'])
                            ->where(function ($item) { return $item['quantity'] > 5; })
                            ->toArray()
                    ];
                })
                ->where(function ($order) {
                    return count($order['items']) > 0;
                });
        },
        "string lambda" => function () use ($DATA) {
            return E::from($DATA->orders)
                ->select(function ($order) {
                    return [
                        'id' => $order['id'],
                        'items' => E::from($order['items'])->where('$v["quantity"] > 5')->toArray()
                    ];
                })
                ->where('count($v["items"]) > 0');
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->orders)
                ->select(function ($order) {
                    return [
                        'id' => $order['id'],
                        'items' => G::from($order['items'])
                            ->where(function ($item) { return $item['quantity'] > 5; })
                            ->toArray()
                    ];
                })
                ->where(function ($order) {
                    return count($order['items']) > 0;
                });
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->orders)
                ->select(function ($order) {
                    return [
                        'id' => $order['id'],
                        'items' => P::from($order['items'])
                            ->where(function ($item) { return $item['quantity'] > 5; })
                            ->asArray()
                    ];
                })
                ->where(function ($order) {
                    return count($order['items']) > 0;
                });
        },
    ]);

benchmark_linq_groups("Sorting arrays of strings", 100, 'consume',
    [
        function () use ($DATA) {
            $sortedStrings = $DATA->strings;
            arsort($sortedStrings, SORT_NATURAL | SORT_FLAG_CASE);
            return $sortedStrings;
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->strings)->orderByDescending(null, 'strnatcasecmp');
        },
        "sort flags" => function () use ($DATA) {
            return E::from($DATA->strings)->orderByDir(SORT_DESC, null, SORT_NATURAL | SORT_FLAG_CASE);
        },
        "callback" => function () use ($DATA) {
            return E::from($DATA->strings)->orderByDescending(
                function ($s) { return $s; },
                function ($a, $b) { return strnatcasecmp($a, $b); }
            );
        },
    ],
    [
        function () use ($DATA) {
            //return G::from($DATA->strings)->orderByDesc('strnatcasecmp');
            return new \Ginq\OrderingGinq(
                new \ArrayIterator($DATA->strings),
                new \Ginq\Comparer\DelegateComparer(function ($a, $b) {
                    return -strnatcasecmp($a, $b);
                })
            );
        },
    ],
    [
        function () use ($DATA) {
            //return P::from($DATA->strings)->orderByDescending(function ($v) { return $v; });
            not_implemented();
        },
    ]);

benchmark_linq_groups("Sorting arrays of objects", 100, 'consume',
    [
        "multisort" => function () use ($DATA) {
            $orderedUsers = $DATA->users;
            $ratings = [ ];
            foreach ($orderedUsers as $k => $user)
                $ratings[$k] = $user['rating'];
            $names = [ ];
            foreach ($orderedUsers as $k => $user)
                $names[$k] = $user['name'];
            $ids = [ ];
            foreach ($orderedUsers as $k => $user)
                $ids[$k] = $user['id'];
            array_multisort(
                $ratings, SORT_DESC, SORT_NUMERIC,
                $names, SORT_ASC, SORT_STRING,
                $ids, SORT_ASC, SORT_NUMERIC,
                $orderedUsers);
            return $orderedUsers;
        },
        "usort" => function () use ($DATA) {
            $orderedUsers = $DATA->users;
            usort(
                $orderedUsers,
                function ($a, $b) {
                    $diff = $a['rating'] - $b['rating'];
                    if ($diff !== 0)
                        return -$diff;
                    $diff = strcmp($a['name'], $b['name']);
                    if ($diff !== 0)
                        return $diff;
                    $diff = $a['id'] - $b['id'];
                    return $diff;
                });
            return $orderedUsers;
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->users)
                ->orderByDescending(function ($u) { return $u['rating']; })
                ->thenBy(function ($u) { return $u['name']; })
                ->thenBy(function ($u) { return $u['id']; });
        },
        "string lambda" => function () use ($DATA) {
            return E::from($DATA->users)->orderByDescending('$v["rating"]')->thenBy('$v["name"]')->thenBy('$v["id"]');
        },
        "sort flags" => function () use ($DATA) {
            return E::from($DATA->users)
                ->orderByDir(SORT_DESC, '$v["rating"]', SORT_NUMERIC)
                ->thenByDir(SORT_ASC, '$v["name"]', SORT_STRING)
                ->thenByDir(SORT_ASC, '$v["id"]', SORT_NUMERIC);
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->users)
                ->orderByDesc(function ($u) { return $u['rating']; })
                ->thenBy(function ($u) { return $u['name']; })
                ->thenBy(function ($u) { return $u['id']; });
        },
        "property path" => function () use ($DATA) {
            return G::from($DATA->users)->orderByDesc('[rating]')->thenBy('[name]')->thenBy('[id]');
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->users)
                ->orderByDescending(function ($u) { return $u['rating']; })
                ->thenByAscending(function ($u) { return $u['name']; })
                ->thenByAscending(function ($u) { return $u['id']; });
        },
    ]);

benchmark_linq_groups("Joining arrays", 100, 'consume',
    [
        function () use ($DATA) {
            $ordersByCustomerId = [ ];
            foreach ($DATA->orders as $order)
                $ordersByCustomerId[$order['customerId']][] = $order;
            $pairs = [ ];
            foreach ($DATA->users as $user) {
                $userId = $user['id'];
                if (isset($ordersByCustomerId[$userId])) {
                    foreach ($ordersByCustomerId[$userId] as $order) {
                        $pairs[] = [
                            'order' => $order,
                            'user' => $user,
                        ];
                    }
                }
            }
            return $pairs;
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->orders)
                ->join($DATA->users,
                    function ($o) { return $o['customerId']; },
                    function ($u) { return $u['id']; },
                    function ($o, $u) {
                        return [
                            'order' => $o,
                            'user' => $u,
                        ];
                    });
        },
        "string lambda" => function () use ($DATA) {
            return E::from($DATA->orders)
                ->join($DATA->users,
                    '$o ==> $o["customerId"]', '$u ==> $u["id"]',
                    '($o, $u) ==> [
                        "order" => $o,
                        "user" => $u,
                    ]');
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->orders)
                ->join($DATA->users,
                    function ($o) { return $o['customerId']; },
                    function ($u) { return $u['id']; },
                    function ($o, $u) {
                        return [
                            'order' => $o,
                            'user' => $u,
                        ];
                    });
        },
        "property path" => function () use ($DATA) {
            return G::from($DATA->orders)
                ->join($DATA->users,
                    '[customerId]', '[id]',
                    function ($o, $u) {
                        return [
                            'order' => $o,
                            'user' => $u,
                        ];
                    });
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->orders)
                ->join($DATA->users)
                ->onEquality(
                    function ($o) { return $o['customerId']; },
                    function ($u) { return $u['id']; }
                )
                ->to(function ($o, $u) {
                    return [
                        'order' => $o,
                        'user' => $u,
                    ];
                });
        },
    ]);

benchmark_linq_groups("Aggregating arrays", 100, null,
    [
        "for" => function () use ($DATA) {
            $sum = 0;
            foreach ($DATA->products as $p)
                $sum += $p['quantity'];
            $avg = 0;
            foreach ($DATA->products as $p)
                $avg += $p['quantity'];
            $avg /= count($DATA->products);
            $min = PHP_INT_MAX;
            foreach ($DATA->products as $p)
                $min = min($min, $p['quantity']);
            $max = -PHP_INT_MAX;
            foreach ($DATA->products as $p)
                $max = max($max, $p['quantity']);
            return "$sum-$avg-$min-$max";
        },
        "array functions" => function () use ($DATA) {
            $sum = array_sum(array_map(function ($p) { return $p['quantity']; }, $DATA->products));
            $avg = array_sum(array_map(function ($p) { return $p['quantity']; }, $DATA->products)) / count($DATA->products);
            $min = min(array_map(function ($p) { return $p['quantity']; }, $DATA->products));
            $max = max(array_map(function ($p) { return $p['quantity']; }, $DATA->products));
            return "$sum-$avg-$min-$max";
        },
    ],
    [
        function () use ($DATA) {
            $sum = E::from($DATA->products)->sum(function ($p) { return $p['quantity']; });
            $avg = E::from($DATA->products)->average(function ($p) { return $p['quantity']; });
            $min = E::from($DATA->products)->min(function ($p) { return $p['quantity']; });
            $max = E::from($DATA->products)->max(function ($p) { return $p['quantity']; });
            return "$sum-$avg-$min-$max";
        },
        "string lambda" => function () use ($DATA) {
            $sum = E::from($DATA->products)->sum('$v["quantity"]');
            $avg = E::from($DATA->products)->average('$v["quantity"]');
            $min = E::from($DATA->products)->min('$v["quantity"]');
            $max = E::from($DATA->products)->max('$v["quantity"]');
            return "$sum-$avg-$min-$max";
        },
    ],
    [
        function () use ($DATA) {
            $sum = G::from($DATA->products)->sum(function ($p) { return $p['quantity']; });
            $avg = G::from($DATA->products)->average(function ($p) { return $p['quantity']; });
            $min = G::from($DATA->products)->min(function ($p) { return $p['quantity']; });
            $max = G::from($DATA->products)->max(function ($p) { return $p['quantity']; });
            return "$sum-$avg-$min-$max";
        },
        "property path" => function () use ($DATA) {
            $sum = G::from($DATA->products)->sum('[quantity]');
            $avg = G::from($DATA->products)->average('[quantity]');
            $min = G::from($DATA->products)->min('[quantity]');
            $max = G::from($DATA->products)->max('[quantity]');
            return "$sum-$avg-$min-$max";
        },
    ],
    [
        function () use ($DATA) {
            $sum = P::from($DATA->products)->sum(function ($p) { return $p['quantity']; });
            $avg = P::from($DATA->products)->average(function ($p) { return $p['quantity']; });
            $min = P::from($DATA->products)->minimum(function ($p) { return $p['quantity']; });
            $max = P::from($DATA->products)->maximum(function ($p) { return $p['quantity']; });
            return "$sum-$avg-$min-$max";
        },
    ]);

benchmark_linq_groups("Aggregating arrays custom", 100, null,
    [
        "for" => function () use ($DATA) {
            $mult = 1;
            foreach ($DATA->products as $p)
                $mult *= $p['quantity'];
            return $mult;
        },
        "array functions" => function () use ($DATA) {
            return array_reduce($DATA->products, function ($a, $p) { return $a * $p['quantity']; }, 1);
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->products)->aggregate(function ($a, $p) { return $a * $p['quantity']; }, 1);
        },
        "string lambda" => function () use ($DATA) {
            return E::from($DATA->products)->aggregate('$a * $v["quantity"]', 1);
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->products)->aggregate(1, function ($a, $p) { return $a * $p['quantity']; });
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->products)
                ->select(function ($p) { return $p['quantity']; })
                ->aggregate(function ($a, $q) { return $a * $q; });
        },
    ]);

benchmark_linq_groups("Process data from ReadMe example", 5,
    function ($e) { consume($e, [ 'products' => null ]); },
    [
        "multisort" => function () use ($DATA) {
            $productsSorted = [ ];
            foreach ($DATA->products as $product) {
                if ($product['quantity'] > 0) {
                    if (empty($productsSorted[$product['catId']]))
                        $productsSorted[$product['catId']] = [ ];
                    $productsSorted[$product['catId']][] = $product;
                }
            }
            foreach ($productsSorted as $catId => $products) {
                $quantities = [ ];
                foreach ($productsSorted[$catId] as $k => $product)
                    $quantities[$k] = $product['quantity'];
                $names = [ ];
                foreach ($productsSorted[$catId] as $k => $product)
                    $names[$k] = $product['name'];
                array_multisort(
                    $quantities, SORT_DESC, SORT_NUMERIC,
                    $names, SORT_ASC, SORT_STRING,
                    $productsSorted[$catId]);
            }
            $categoriesSorted = $DATA->categories;
            $names = [ ];
            foreach ($categoriesSorted as $k => $category)
                $names[$k] = $category['name'];
            array_multisort(
                $names, SORT_ASC, SORT_STRING,
                $categoriesSorted);
            $result = [ ];
            foreach ($categoriesSorted as $category) {
                $categoryId = $category['id'];
                $result[$category['id']] = [
                    'name' => $category['name'],
                    'products' => isset($productsSorted[$categoryId]) ? $productsSorted[$categoryId] : [ ],
                ];
            }
            return $result;
        },
        "usort" => function () use ($DATA) {
            $productsSorted = [ ];
            foreach ($DATA->products as $product) {
                if ($product['quantity'] > 0) {
                    if (empty($productsSorted[$product['catId']]))
                        $productsSorted[$product['catId']] = [ ];
                    $productsSorted[$product['catId']][] = $product;
                }
            }
            foreach ($productsSorted as $catId => $products) {
                usort($productsSorted[$catId], function ($a, $b) {
                    $diff = $a['quantity'] - $b['quantity'];
                    if ($diff != 0)
                        return -$diff;
                    $diff = strcmp($a['name'], $b['name']);
                    return $diff;
                });
            }
            $result = [ ];
            $categoriesSorted = $DATA->categories;
            usort($categoriesSorted, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            foreach ($categoriesSorted as $category) {
                $categoryId = $category['id'];
                $result[$category['id']] = [
                    'name' => $category['name'],
                    'products' => isset($productsSorted[$categoryId]) ? $productsSorted[$categoryId] : [ ],
                ];
            }
            return $result;
        },
    ],
    [
        function () use ($DATA) {
            return E::from($DATA->categories)
                ->orderBy(function ($cat) { return $cat['name']; })
                ->groupJoin(
                    from($DATA->products)
                        ->where(function ($prod) { return $prod['quantity'] > 0; })
                        ->orderByDescending(function ($prod) { return $prod['quantity']; })
                        ->thenBy(function ($prod) { return $prod['name']; }),
                    function ($cat) { return $cat['id']; },
                    function ($prod) { return $prod['catId']; },
                    function ($cat, $prods) {
                        return array(
                            'name' => $cat['name'],
                            'products' => $prods
                        );
                    }
                );
        },
        "string lambda" => function () use ($DATA) {
            return E::from($DATA->categories)
                ->orderBy('$cat ==> $cat["name"]')
                ->groupJoin(
                    from($DATA->products)
                        ->where('$prod ==> $prod["quantity"] > 0')
                        ->orderByDescending('$prod ==> $prod["quantity"]')
                        ->thenBy('$prod ==> $prod["name"]'),
                    '$cat ==> $cat["id"]', '$prod ==> $prod["catId"]',
                    '($cat, $prods) ==> [
                            "name" => $cat["name"],
                            "products" => $prods
                        ]');
        },
    ],
    [
        function () use ($DATA) {
            return G::from($DATA->categories)
                ->orderBy(function ($cat) { return $cat['name']; })
                ->groupJoin(
                    G::from($DATA->products)
                        ->where(function ($prod) { return $prod['quantity'] > 0; })
                        ->orderByDesc(function ($prod) { return $prod['quantity']; })
                        ->thenBy(function ($prod) { return $prod['name']; }),
                    function ($cat) { return $cat['id']; },
                    function ($prod) { return $prod['catId']; },
                    function ($cat, $prods) {
                        return array(
                            'name' => $cat['name'],
                            'products' => $prods
                        );
                    }
                );
        },
    ],
    [
        function () use ($DATA) {
            return P::from($DATA->categories)
                ->orderByAscending(function ($cat) { return $cat['name']; })
                ->groupJoin(
                    P::from($DATA->products)
                        ->where(function ($prod) { return $prod['quantity'] > 0; })
                        ->orderByDescending(function ($prod) { return $prod['quantity']; })
                        ->thenByAscending(function ($prod) { return $prod['name']; })
                )
                ->onEquality(
                    function ($cat) { return $cat['id']; },
                    function ($prod) { return $prod['catId']; }
                )
                ->to(function ($cat, $prods) {
                    return array(
                        'name' => $cat['name'],
                        'products' => $prods
                    );
                });
        },
    ]);

echo "\nDone!\n";