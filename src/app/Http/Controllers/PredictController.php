<?php

namespace App\Http\Controllers;

use App\Support\MlClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;

class PredictController extends Controller
{
    public function __invoke(Request $request)
    {
        $features = MlClient::FEATURES;
        $input = collect($features)->map(fn ($f) => $f[2])->all();
        $result = null;

        if ($request->has('pm25')) {
            $rules = collect($features)->map(fn () => ['required', 'numeric', 'min:0', 'max:20000'])->all();
            $rules['humidity'] = ['required', 'numeric', 'min:0', 'max:100'];
            $rules['temperature'] = ['required', 'numeric', 'min:-10', 'max:60'];
            $v = Validator::make($request->only(array_keys($features)), $rules, [
                'required' => ':attribute wajib diisi.',
                'numeric' => ':attribute harus berupa angka.',
                'min' => ':attribute minimal :min.',
                'max' => ':attribute maksimal :max.',
            ], collect($features)->map(fn ($f) => $f[0])->all());

            $input = array_merge($input, $request->only(array_keys($features)));
            if ($v->fails()) {
                view()->share('errors', (new ViewErrorBag)->put('default', $v->errors()));
            } else {
                try {
                    $result = MlClient::predict($v->validated());
                } catch (RuntimeException $e) {
                    report($e);
                    view()->share('errors', (new ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag([
                        'ml' => 'Model prediksi sedang tidak tersedia. Coba lagi beberapa saat lagi.',
                    ])));
                }
            }
        }

        $metricsFile = storage_path('app/knn_metrics.json');
        $metrics = is_file($metricsFile) ? json_decode(file_get_contents($metricsFile), true) : null;

        return view('predict', compact('features', 'input', 'result', 'metrics'));
    }
}
