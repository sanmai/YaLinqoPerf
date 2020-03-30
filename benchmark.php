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

require_once 'vendor/autoload.php';
require_once 'data.php';

use Pipeline\Standard as S;
use YaLinqo\Enumerable as E;

function is_cli()
{
    // PROMPT var is set by cmd, but not by PHPStorm.
    return \PHP_SAPI === 'cli' && isset($_SERVER['PROMPT']);
}

function xrange($start, $limit, $step = 1)
{
    \assert($start < $limit);

    for ($i = $start; $i <= $limit; $i += $step) {
        yield $i;
    }
}

function benchmark_operation($count, $consume, $operation)
{
    $time = \microtime(true);
    $message = 'Success';
    $return = null;

    try {
        for ($i = 0; $i < $count; ++$i) {
            $return = $operation();
            if (null !== $consume) {
                $consume($return);
            }
        }
        $return = $operation();
        if (!\is_scalar($return)) {
            $return = E::from($return)->toListDeep();
        }
        $result = true;
    } catch (Exception $e) {
        $result = false;
        $message = $e->getMessage();
    }

    return [
        'result' => $result,
        'return' => $return,
        'message' => $message,
        'time' => (\microtime(true) - $time) / $count,
    ];
}

function benchmark_array($name, $count, $consume, $benches): void
{
    if (isset($_SERVER['ONLY']) && false === \strpos($name, $_SERVER['ONLY'])) {
        // not testing because not requested
        return;
    }

    $benches = E::from($benches)->select('[ "name" => $k, "op" => $v ]')->toArray();

    // Run benchmarks
    echo "\n{$name} ";
    foreach ($benches as $k => $bench) {
        $benches[$k] = \array_merge($bench, benchmark_operation($count, $consume, $bench['op']));
        echo '.';
    }
    // Remove progress dots with backspaces
    if (is_cli()) {
        echo \str_repeat(\chr(8), \count($benches) + 1).\str_repeat(' ', \count($benches) + 1);
    }

    // Validate results
    $results = E::from($benches)->select(function ($b) {
        return [
            'name' => $b['name'],
            'return' => $b['result'] ? \json_encode($b['return'], JSON_PRETTY_PRINT) : null,
        ];
    })->toList();
    $return = $results[0]['return'];
    for ($i = 1; $i < \count($results); ++$i) {
        if (null === $results[$i]['return']) {
            continue;
        }
        $returnOther = $results[$i]['return'];
        $match = $return === $returnOther;

        if (!$match && \is_numeric($return) && \is_numeric($returnOther)) {
            $match = \abs($returnOther - $return) < .0000000001;
        }

        if (!$match) {
            echo "\nERROR: Results from tests '{$results[0]['name']}' and '{$results[$i]['name']}' do not match.\n";
            echo "0: {$return}\n{$i}: {$returnOther}\n";
            \file_put_contents('tmp/result-0.txt', $return);
            \file_put_contents('tmp/result-1.txt', $returnOther);
            die;
        }
    }

    // Draw table
    echo "\n".\str_repeat('-', \strlen($name))."\n";
    $min = E::from($benches)->where('$v["result"]')->min('$v["time"]');
    if (0 === $min) {
        $min = 0.0001;
    }
    foreach ($benches as $bench) {
        echo '  '.\str_pad($bench['name'], 28).
            ($bench['result']
                ? \number_format($bench['time'], 5).' sec   '
                .'x'.\number_format($bench['time'] / $min, 1).' '
                .($bench['time'] !== $min
                    ? '(+'.\number_format(($bench['time'] / $min - 1) * 100, 0, '.', '').'%)'
                    : '(100%)')
                : '* '.$bench['message'])
            ."\n";
    }
}

function benchmark_linq_groups($name, $count, $consume, $opsPhp, $opsPipeline): void
{
    $benches = E::from(\array_map(function ($callback) {
        if (null === $callback) {
            return [function (): void {
                not_implemented();
            }];
        }

        return $callback;
    }, [
        'PHP      ' => $opsPhp,
        'Pipeline ' => $opsPipeline,
    ]))->selectMany(
        '$ops ==> $ops',
        '$op ==> $op',
        '($op, $name, $detail) ==> is_numeric($detail) ? $name : "$name [$detail]"'
    );
    benchmark_array($name, $count, $consume, $benches);
}

function not_implemented(): void
{
    throw new Exception('Not implemented');
}

function consume($array, $props = null): void
{
    foreach ($array as $k => $v) {
        if (null !== $props) {
            foreach ($props as $prop => $subprops) {
                consume($v[$prop], $subprops);
            }
        }
    }
}

//------------------------------------------------------------------------------

$ITER_MAX = isset($_SERVER['argv'][1]) ? (int) $_SERVER['argv'][1] : 100;

echo "Preparing data with {$ITER_MAX} items...\t";
$DATA = new SampleData($ITER_MAX);
echo "Done\n";

benchmark_linq_groups(
    "Iterating over {$ITER_MAX} ints",
    100,
    null,
    [
        'for' => function () use ($ITER_MAX) {
            $j = null;
            for ($i = 0; $i < $ITER_MAX; ++$i) {
                $j = $i;
            }

            return $j;
        },
        'array functions' => function () use ($ITER_MAX) {
            $j = null;
            foreach (\range(0, $ITER_MAX - 1) as $i) {
                $j = $i;
            }

            return $j;
        },
    ],
    [
        function () use ($ITER_MAX) {
            $j = null;
            foreach (new S(new \ArrayIterator(\range(0, $ITER_MAX - 1))) as $i) {
                $j = $i;
            }

            return $j;
        },
        'generator' => function () use ($ITER_MAX) {
            $j = null;
            foreach (new S(xrange(0, $ITER_MAX - 1)) as $i) {
                $j = $i;
            }

            return $j;
        },
    ]
);

benchmark_linq_groups(
    "Generating array of {$ITER_MAX} integers",
    100,
    'consume',
    [
        'for' => function () use ($ITER_MAX) {
            $a = [];
            for ($i = 0; $i < $ITER_MAX; ++$i) {
                $a[] = $i;
            }

            return $a;
        },
        'array functions' => function () use ($ITER_MAX) {
            return \range(0, $ITER_MAX - 1);
        },
        'xrange' => function () use ($ITER_MAX) {
            return xrange(0, $ITER_MAX - 1);
        },
    ],
    [
        'range' => function () use ($ITER_MAX) {
            return new S(new \ArrayIterator(\range(0, $ITER_MAX - 1)));
        },
        'xrange' => function () use ($ITER_MAX) {
            return new S(xrange(0, $ITER_MAX - 1));
        },
    ]
);

benchmark_linq_groups(
    "Generating lookup of {$ITER_MAX} floats, calculate sum",
    100,
    null,
    [
        function () use ($ITER_MAX) {
            $dic = [];
            for ($i = 0; $i < $ITER_MAX; ++$i) {
                $dic[(string) \tan($i % 100)][] = $i % 2 ? \sin($i) : \cos($i);
            }
            $sum = 0;
            foreach ($dic as $g) {
                foreach ($g as $v) {
                    $sum += $v;
                }
            }

            return $sum;
        },
    ],
    [
        function () use ($ITER_MAX) {
            $s = new S(new \ArrayIterator(\range(0, $ITER_MAX - 1)));

            $s->map(function ($i) {
                return [
                    (string) \tan($i % 100),
                    $i % 2 ? \sin($i) : \cos($i),
                ];
            });

            $s->unpack(function ($tan, $sincos) {
                return $sincos;
            });

            return $s->reduce();
        },
    ]
);

benchmark_linq_groups(
    'Counting values in arrays',
    100,
    null,
    [
        'for' => function () use ($DATA) {
            $numberOrders = 0;
            foreach ($DATA->orders as $order) {
                if (\count($order['items']) > 5) {
                    ++$numberOrders;
                }
            }

            return $numberOrders;
        },
        'array functions' => function () use ($DATA) {
            return \count(
                \array_filter(
                    $DATA->orders,
                    function ($order) { return \count($order['items']) > 5; }
                )
            );
        },
    ],
    [
        function () use ($DATA) {
            $s = new S(new \ArrayIterator($DATA->orders));

            return $s->map(function ($order) {
                return \count($order['items']) > 5;
            })->reduce();
        },
    ]
);

benchmark_linq_groups(
    'Counting values in arrays deep',
    100,
    null,
    [
        'for' => function () use ($DATA) {
            $numberOrders = 0;
            foreach ($DATA->orders as $order) {
                $numberItems = 0;
                foreach ($order['items'] as $item) {
                    if ($item['quantity'] > 5) {
                        ++$numberItems;
                    }
                    if ($numberItems > 2) {
                        ++$numberOrders;

                        break;
                    }
                }
            }

            return $numberOrders;
        },
        'array functions' => function () use ($DATA) {
            return \count(
                \array_filter(
                    $DATA->orders,
                    function ($order) {
                        return \count(
                            \array_filter(
                                $order['items'],
                                function ($item) { return $item['quantity'] > 5; }
                            )
                        ) > 2;
                    }
                )
            );
        },
    ],
    [
        function () use ($DATA) {
            $s = new S(new \ArrayIterator($DATA->orders));

            $s->map(function ($order) {
                $s = new S(new \ArrayIterator($order['items']));

                return $s->map(function ($item) {
                    return $item['quantity'] > 5;
                })->reduce();
            });

            $s->map(function ($count) {
                return $count > 2;
            });

            return $s->reduce();
        },
    ]
);

benchmark_linq_groups(
    'Filtering values in arrays',
    100,
    'consume',
    [
        'for' => function () use ($DATA) {
            $filteredOrders = [];
            foreach ($DATA->orders as $order) {
                if (\count($order['items']) > 5) {
                    $filteredOrders[] = $order;
                }
            }

            return $filteredOrders;
        },
        'yield' => function () use ($DATA) {
            foreach ($DATA->orders as $order) {
                if (\count($order['items']) > 5) {
                    yield $order;
                }
            }
        },
        'array functions' => function () use ($DATA) {
            return \array_filter(
                $DATA->orders,
                function ($order) { return \count($order['items']) > 5; }
            );
        },
    ],
    [
        function () use ($DATA) {
            $s = new S(new \ArrayIterator($DATA->orders));
            $s->filter(function ($order) {
                return \count($order['items']) > 5;
            });

            return $s;
        },
    ]
);

benchmark_linq_groups(
    'Filtering values in arrays deep',
    100,
    function ($e): void { consume($e, ['items' => null]); },
    [
        'for' => function () use ($DATA) {
            $filteredOrders = [];
            foreach ($DATA->orders as $order) {
                $filteredItems = [];
                foreach ($order['items'] as $item) {
                    if ($item['quantity'] > 5) {
                        $filteredItems[] = $item;
                    }
                }
                if (\count($filteredItems) > 0) {
                    $order['items'] = $filteredItems;
                    $filteredOrders[] = [
                        'id' => $order['id'],
                        'items' => $filteredItems,
                    ];
                }
            }

            return $filteredOrders;
        },
        'array functions' => function () use ($DATA) {
            return \array_filter(
                \array_map(
                    function ($order) {
                        return [
                            'id' => $order['id'],
                            'items' => \array_filter(
                                $order['items'],
                                function ($item) { return $item['quantity'] > 5; }
                            ),
                        ];
                    },
                    $DATA->orders
                ),
                function ($order) {
                    return \count($order['items']) > 0;
                }
            );
        },
    ],
    [
        function () use ($DATA) {
            $s = new S(new \ArrayIterator($DATA->orders));

            $s->map(function ($order) {
                $s = new S(new \ArrayIterator($order['items']));
                $order['items'] = $s->filter(function ($item) {
                    return $item['quantity'] > 5;
                });

                return $order;
            });

            $s->map(function ($order) {
                return [
                    'id' => $order['id'],
                    'items' => \iterator_to_array($order['items']),
                ];
            });

            $s->filter(function ($order) {
                return \count($order['items']) > 0;
            });

            return $s;
        },
    ]
);

benchmark_linq_groups(
    'Joining arrays',
    100,
    'consume',
    [
        function () use ($DATA) {
            $ordersByCustomerId = [];
            foreach ($DATA->orders as $order) {
                $ordersByCustomerId[$order['customerId']][] = $order;
            }
            $pairs = [];
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
            $s = new S(new \ArrayIterator($DATA->orders));
            $ordersByCustomerId = $s->reduce(function ($ordersByCustomerId, $order): void {
                $ordersByCustomerId[$order['customerId']][] = &$order;
            }, []);

            $s = new S(new \ArrayIterator($DATA->users));
            $s->map(function ($user) use ($ordersByCustomerId) {
                if (isset($ordersByCustomerId[$user['id']])) {
                    foreach ($ordersByCustomerId[$user['id']] as $order) {
                        yield [
                            'order' => &$order,
                            'user' => $user,
                        ];
                    }
                }
            });

            return $s;
        },
    ]
);

benchmark_linq_groups(
    'Aggregating arrays',
    100,
    null,
    [
        'for' => function () use ($DATA) {
            $sum = 0;
            foreach ($DATA->products as $p) {
                $sum += $p['quantity'];
            }
            $avg = 0;
            foreach ($DATA->products as $p) {
                $avg += $p['quantity'];
            }
            $avg /= \count($DATA->products);
            $min = PHP_INT_MAX;
            foreach ($DATA->products as $p) {
                $min = \min($min, $p['quantity']);
            }
            $max = -PHP_INT_MAX;
            foreach ($DATA->products as $p) {
                $max = \max($max, $p['quantity']);
            }

            return "{$sum}-{$avg}-{$min}-{$max}";
        },
        'array functions' => function () use ($DATA) {
            $sum = \array_sum(\array_map(function ($p) { return $p['quantity']; }, $DATA->products));
            $avg = \array_sum(\array_map(function ($p) { return $p['quantity']; }, $DATA->products)) / \count($DATA->products);
            $min = \min(\array_map(function ($p) { return $p['quantity']; }, $DATA->products));
            $max = \max(\array_map(function ($p) { return $p['quantity']; }, $DATA->products));

            return "{$sum}-{$avg}-{$min}-{$max}";
        },
        'optimized' => function () use ($DATA) {
            $qtys = \array_map(function ($p) { return $p['quantity']; }, $DATA->products);
            $sum = \array_sum($qtys);
            $avg = $sum / \count($qtys);
            $min = \min($qtys);
            $max = \max($qtys);

            return "{$sum}-{$avg}-{$min}-{$max}";
        },
    ],
    [
        function () use ($DATA) {
            $qtys = \iterator_to_array((new S(new \ArrayIterator($DATA->products)))->map(function ($p) {
                return $p['quantity'];
            }));

            $sum = \array_sum($qtys);
            $avg = $sum / \count($qtys);
            $min = \min($qtys);
            $max = \max($qtys);

            return "{$sum}-{$avg}-{$min}-{$max}";
        },
    ]
);

benchmark_linq_groups(
    'Aggregating arrays custom',
    100,
    null,
    [
        'for' => function () use ($DATA) {
            $mult = 1;
            foreach ($DATA->products as $p) {
                $mult *= $p['quantity'];
            }

            return $mult;
        },
        'array functions' => function () use ($DATA) {
            return \array_reduce($DATA->products, function ($a, $p) { return $a * $p['quantity']; }, 1);
        },
    ],
    [
        function () use ($DATA) {
            $s = new S(new \ArrayIterator($DATA->products));

            return $s->reduce(function ($carry, $p) {
                return $carry * $p['quantity'];
            }, 1);
        },
    ]
);

echo "\nDone!\n";
