<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MapController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// routes/web.php หรือ routes/api.php
// throttle กำหนด rate limit req ที่เข้ามา 100 requests ภายใน 1 นาที
Route::middleware('throttle:100,1')->group(function () {
    Route::get('/search-restaurants', [MapController::class, 'searchRestaurantsNew']);
});

