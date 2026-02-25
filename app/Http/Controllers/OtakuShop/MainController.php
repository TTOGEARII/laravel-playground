<?php

namespace App\Http\Controllers\OtakuShop;

use App\Http\Controllers\Controller;

class MainController extends Controller
{
    public function index()
    {
        return view('otaku-shop.index');
    }
}
