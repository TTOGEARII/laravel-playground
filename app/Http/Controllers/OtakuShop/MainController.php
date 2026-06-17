<?php

namespace App\Http\Controllers\OtakuShop;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class MainController extends Controller
{
    public function index(): View
    {
        return view('otaku-shop.index');
    }
}
