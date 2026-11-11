<?php

namespace App\Support\Ml;

/**
 * Decision Tree CART murni PHP.
 * Mode 'class' memakai Gini impurity, mode 'reg' memakai jumlah kuadrat galat (SSE).
 */
class DecisionTree
{
    private array $X = [];
    private array $y = [];
    private array $root = [];

    public function __construct(
        private string $mode = 'class',
        private int $maxDepth = 8,
        private int $minLeaf = 5,
    ) {}

    /** @param list<list<float>> $X  @param list<string|float> $y */
    public function fit(array $X, array $y): self
    {
        $this->X = $X;
        $this->y = $y;
        $this->root = $this->build(array_keys($X), 0);
        $this->X = $this->y = [];

        return $this;
    }

    public function predict(array $x): string|float
    {
        $node = $this->root;
        while (isset($node['f'])) {
            $node = $x[$node['f']] <= $node['t'] ? $node['l'] : $node['r'];
        }

        return $node['v'];
    }

    private function leaf(array $idx): array
    {
        if ($this->mode === 'reg') {
            $s = 0.0;
            foreach ($idx as $i) {
                $s += $this->y[$i];
            }

            return ['v' => $s / count($idx)];
        }
        $counts = [];
        foreach ($idx as $i) {
            $counts[$this->y[$i]] = ($counts[$this->y[$i]] ?? 0) + 1;
        }
        arsort($counts);

        return ['v' => (string) array_key_first($counts)];
    }

    private function build(array $idx, int $depth): array
    {
        $n = count($idx);
        if ($depth >= $this->maxDepth || $n < 2 * $this->minLeaf) {
            return $this->leaf($idx);
        }
        $split = $this->mode === 'reg' ? $this->bestRegSplit($idx) : $this->bestClassSplit($idx);
        if ($split === null) {
            return $this->leaf($idx);
        }
        [$f, $t] = $split;
        $left = $right = [];
        foreach ($idx as $i) {
            if ($this->X[$i][$f] <= $t) {
                $left[] = $i;
            } else {
                $right[] = $i;
            }
        }

        return ['f' => $f, 't' => $t, 'l' => $this->build($left, $depth + 1), 'r' => $this->build($right, $depth + 1)];
    }

    /** @return array{0:int,1:float}|null */
    private function bestClassSplit(array $idx): ?array
    {
        $n = count($idx);
        $total = [];
        foreach ($idx as $i) {
            $total[$this->y[$i]] = ($total[$this->y[$i]] ?? 0) + 1;
        }
        if (count($total) < 2) {
            return null;
        }
        $sqAll = 0;
        foreach ($total as $c) {
            $sqAll += $c * $c;
        }
        $parent = $n - $sqAll / $n;   // n * gini(parent)
        $best = $parent - 1e-9;
        $result = null;

        foreach (array_keys($this->X[$idx[0]]) as $f) {
            $vals = [];
            foreach ($idx as $i) {
                $vals[$i] = $this->X[$i][$f];
            }
            asort($vals);
            $order = array_keys($vals);
            $left = [];
            $right = $total;
            $sqL = 0;
            $sqR = $sqAll;
            for ($p = 0; $p < $n - 1; $p++) {
                $c = $this->y[$order[$p]];
                $cl = $left[$c] ?? 0;
                $sqL += 2 * $cl + 1;
                $left[$c] = $cl + 1;
                $sqR -= 2 * $right[$c] - 1;
                $right[$c]--;
                $nL = $p + 1;
                $nR = $n - $nL;
                if ($nL < $this->minLeaf || $nR < $this->minLeaf) {
                    continue;
                }
                $v = $vals[$order[$p]];
                $next = $vals[$order[$p + 1]];
                if ($v === $next) {
                    continue;
                }
                $imp = ($nL - $sqL / $nL) + ($nR - $sqR / $nR);
                if ($imp < $best) {
                    $best = $imp;
                    $result = [$f, ($v + $next) / 2];
                }
            }
        }

        return $result;
    }

    /** @return array{0:int,1:float}|null */
    private function bestRegSplit(array $idx): ?array
    {
        $n = count($idx);
        $sum = $sq = 0.0;
        foreach ($idx as $i) {
            $sum += $this->y[$i];
            $sq += $this->y[$i] ** 2;
        }
        $parent = $sq - $sum * $sum / $n;
        if ($parent <= 1e-12) {
            return null;
        }
        $best = $parent - 1e-9;
        $result = null;

        foreach (array_keys($this->X[$idx[0]]) as $f) {
            $vals = [];
            foreach ($idx as $i) {
                $vals[$i] = $this->X[$i][$f];
            }
            asort($vals);
            $order = array_keys($vals);
            $sL = $qL = 0.0;
            for ($p = 0; $p < $n - 1; $p++) {
                $t = $this->y[$order[$p]];
                $sL += $t;
                $qL += $t * $t;
                $nL = $p + 1;
                $nR = $n - $nL;
                if ($nL < $this->minLeaf || $nR < $this->minLeaf) {
                    continue;
                }
                $v = $vals[$order[$p]];
                $next = $vals[$order[$p + 1]];
                if ($v === $next) {
                    continue;
                }
                $sR = $sum - $sL;
                $sse = ($qL - $sL * $sL / $nL) + (($sq - $qL) - $sR * $sR / $nR);
                if ($sse < $best) {
                    $best = $sse;
                    $result = [$f, ($v + $next) / 2];
                }
            }
        }

        return $result;
    }
}
