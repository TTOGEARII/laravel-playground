<?php

namespace App\Http\Controllers\OtakuShop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MainController extends Controller
{
    public function index(){
        return view('otaku-shop.index');
    }
}
