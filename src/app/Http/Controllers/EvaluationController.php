<?php

namespace App\Http\Controllers;

use App\Console\Commands\EvaluateModels;

class EvaluationController extends Controller
{
    public function __invoke()
    {
        $file = storage_path(EvaluateModels::FILE);
        $eval = is_file($file) ? json_decode(file_get_contents($file), true) : null;

        return view('evaluation', compact('eval'));
    }
}
