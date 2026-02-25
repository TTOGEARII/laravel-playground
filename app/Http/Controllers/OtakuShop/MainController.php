<?php

namespace App\Http\Controllers\OtakuShop;

use App\Http\Controllers\Controller;
use App\Services\OtakuShop\ProductService;

class MainController extends Controller
{
    protected ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function index()
    {
        // 현재는 UI가 더미 데이터를 사용하고 있지만,
        // 이후 blade 에서 $products 를 활용해 실제 데이터를 바인딩할 수 있습니다.
        $products = $this->productService->listProducts([
            'active_only' => true,
        ], 15);

        return view('otaku-shop.index', [
            'products' => $products,
        ]);
    }
}
