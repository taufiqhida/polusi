<?php

namespace App\Support\Ml;

/** Gaussian Naive Bayes: tiap fitur dianggap berdistribusi normal di dalam tiap kelas. */
class NaiveBayes
{
    private array $prior = [];
    private array $mean = [];
    private array $var = [];

    /** @param list<list<float>> $X  @param list<string> $y */
    public function fit(array $X, array $y): self
    {
        $d = count($X[0]);
        $groups = [];
        foreach ($y as $i => $label) {
            $groups[$label][] = $X[$i];
        }

        $maxVar = 0.0;
        foreach ($groups as $label => $rows) {
            $n = count($rows);
            $this->prior[$label] = log($n / count($y));
            for ($j = 0; $j < $d; $j++) {
                $col = array_column($rows, $j);
                $m = array_sum($col) / $n;
                $v = array_sum(array_map(fn ($x) => ($x - $m) ** 2, $col)) / $n;
                $this->mean[$label][$j] = $m;
                $this->var[$label][$j] = $v;
                $maxVar = max($maxVar, $v);
            }
        }
        // var smoothing seperti scikit-learn agar tidak membagi dengan nol
        $eps = 1e-9 * max($maxVar, 1e-9);
        foreach ($this->var as $label => $vars) {
            foreach ($vars as $j => $v) {
                $this->var[$label][$j] = $v + $eps;
            }
        }

        return $this;
    }

    public function predict(array $x): string
    {
        $best = null;
        $bestScore = -INF;
        foreach ($this->prior as $label => $logPrior) {
            $s = $logPrior;
            foreach ($x as $j => $v) {
                $var = $this->var[$label][$j];
                $s -= 0.5 * log(2 * M_PI * $var) + ($v - $this->mean[$label][$j]) ** 2 / (2 * $var);
            }
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $label;
            }
        }

        return (string) $best;
    }
}
