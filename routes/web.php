<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
/**
该文件会被require 引入，并运行，运行时触发伪装基类
从而实例化Router
 **/
//Route::get('/', function () {
//    return view('welcome');
//});

/**
找到门面伪装类，并实例化Router类对象返回
触发门面的基类__callStatic
 **/
Route::group(['middleware'=>'user.verify','prefix'=>'admin'],function (){
    //Route::get("user/index","UsersController@index");

    /**
    Route触发门面基类并实例Router->get("user/test","UsersController@test")方法
     **/
    Route::get("user/index","UsersController@test");
});

