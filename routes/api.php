<?php

use App\Http\Controllers\ImageController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

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

Route::post('/name-is-free', [UserController::class, 'nameIsFree']);
Route::post('/email-is-free', [UserController::class, 'emailIsFree']);
Route::post('/register', [UserController::class, 'register']);
Route::post('/login', [UserController::class, 'login']);

Route::get('/posts', [PostController::class, 'feed']);
Route::get('/posts/by-tag/{tagSlug}', [PostController::class, 'listByTag']);
Route::get('/posts/{slug}', [PostController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
  Route::get('/logout', [UserController::class, 'logout']);

  Route::get('/user', [UserController::class, 'index']);

  Route::post('/user/check-password', [UserController::class, 'checkPassword']);
  Route::post('/user/update-password', [UserController::class, 'updatePassword']);

  Route::post('/user/avatar', [UserController::class, 'setAvatar']);
  Route::delete('/user/avatar', [UserController::class, 'deleteAvatar']);

  Route::get('/avatars', [ImageController::class, 'listOfAvatars']);
  Route::post('/avatars', [ImageController::class, 'storeAvatar']);
  Route::delete('/avatars/{id}', [ImageController::class, 'destroyAvatar']);

  Route::get('/posts/{post}/like-add', [LikeController::class, 'addPostLike']);
  Route::get('/posts/{post}/like-destroy', [LikeController::class, 'destroyPostLike']);

  Route::middleware('is.admin')->group(function () {
    Route::get('/admin/posts', [PostController::class, 'listForAdmin']);
    Route::get('/admin/posts/{slug}', [PostController::class, 'showForAdmin']);
    Route::post('/admin/posts', [PostController::class, 'store']);
    Route::post('/admin/posts/{post}', [PostController::class, 'update']);
    Route::delete('/admin/posts/{post}', [PostController::class, 'destroy']);

    Route::get('/images/post/{post}', [ImageController::class, 'listForPost']);
    Route::post('/images', [ImageController::class, 'storeAttachedToPost']);
    Route::delete('/images/{id}', [ImageController::class, 'destroyImage']);

    Route::get('/admin/tags', [TagController::class, 'list']);
    Route::post('/admin/tags/check-name', [TagController::class, 'checkNameIsFree']);
    Route::post('/admin/tags', [TagController::class, 'store']);
    Route::post('/admin/tags/{tag}', [TagController::class, 'update']);
    Route::delete('/admin/tags/{tag}', [TagController::class, 'destroy']);
  });
});

/**
 * Allows you to delete images that are not attached to the post
 * from the created directory and the directory itself.
 *
 * Removed from middleware is.admin to resolve the situation with logging out
 * of the account during the creation of the post.
 */
Route::post('/images/clear', [ImageController::class, 'clearNonAttached']);
