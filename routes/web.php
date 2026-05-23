<?php

use App\Http\Middleware\SetLocale;
use App\Models\Operator;
use App\Support\CurrentOperator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, SetLocale::SUPPORTED, true)) {
        session(['locale' => $locale]);
    }

    return back();
})->name('locale.switch');

Route::get('/operator/switch/{operator?}', function (?string $operator = null) {
    if ($operator === null || $operator === 'all') {
        CurrentOperator::clear();
    } else {
        $op = Operator::where('slug', $operator)->where('is_active', true)->first();
        if ($op) {
            CurrentOperator::set($op->id);
        }
    }

    return back(fallback: '/admin');
})->middleware(['web', 'auth'])->name('operator.switch');
