<?php

namespace App\Http\Controllers;

use App\Exceptions\DataConflictException;
use App\Exceptions\FailedDeletingDirectoryException;
use App\Exceptions\FailedRequestDBException;
use App\Http\Resources\ImageResource;
use App\Http\Resources\PostPaginatedCollection;
use App\Http\Resources\PostResource;
use App\Models\Image;
use App\Models\Post;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Log;
use Nette\DirectoryNotFoundException;
use Storage;
use Str;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PostController extends Controller
{
  /**
   * The number of records displayed on the page
   *
   * @var integer
   */
  protected $pageLimit = 10;

  /**
   * Returns a paginated list of posts for the feed
   *
   * @param Request $request
   * @return \Illuminate\Http\Response
   */
  public function feed(Request $request)
  {
    // return response()->json(["error" => "Test error"], 500);
    $postList = Post::where('is_published', true)->orderBy('published_at', 'desc')->paginate($this->pageLimit)->withPath('');
    return response()->json(new PostPaginatedCollection($postList), 200);
  }

  /**
   * Returns a paginated list of posts to display in the admin panel
   *
   * @param Request $request
   * @return \Illuminate\Http\Response
   */
  public function listForAdmin(Request $request)
  {
    // return response()->json(["error" => "Test error"], 500);
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    $postList = Post::orderBy('id', 'desc')->paginate($this->pageLimit)->withPath('');
    return response()->json(new PostPaginatedCollection($postList), 200);
  }

  /**
   * Returns the post data for output to the user
   *
   * @param Request $request
   * @param string $slug
   * @return \Illuminate\Http\Response
   */
  public function show(Request $request, $slug)
  {
    // return response()->json(["error" => "Test error"], 500);
    $post = null;
    if (ctype_digit($slug)) $post = Post::whereId($slug)->first();
    if (!$post) $post = Post::whereSlug($slug)->first();
    if (!$post) {
      throw new ModelNotFoundException("Post was not found");
    }

    $user = $request->user();
    if (!$post->is_published && (!$user || !$user->isAdmin())) {
      throw new AccessDeniedHttpException('Access denied');
    }

    return response()->json(new PostResource($post), 200);
  }

  /**
   * Returns the post data for editing in the admin panel
   *
   * @param Request $request
   * @param string $slug
   * @return \Illuminate\Http\Response
   */
  public function showForAdmin(Request $request, $slug)
  {
    // return response()->json(["error" => 'test error'], 500);
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    $post = null;
    if (ctype_digit($slug)) $post = Post::whereId($slug)->first();
    if (!$post) $post = Post::whereSlug($slug)->first();
    if (!$post) {
      throw new ModelNotFoundException();
    }

    $images = $post->images()->get();
    return response()->json(['post' => new PostResource($post), 'images' => $images ? ImageResource::collection($images) : []], 200);
  }

  /**
   * Saves a new post
   *
   * @param Request $request
   * @return \Illuminate\Http\Response
   */
  public function store(Request $request)
  {
    // return response()->json(["error" => 'test error'], 500);
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    if (!$request->has('image_counter')) {
      Log::error("PostController->store: image_counter not received.");
      throw new BadRequestException("Bad request: image_counter not received");
    }
    $imageCounter = $request->integer('image_counter');

    $postData = $request->only('title', 'slug', 'excerpt_raw', 'excerpt_html', 'content_raw', 'content_html', 'is_published', 'image_path');
    if (!$postData['slug']) {
      $postData['slug'] = Str::slug($postData['title'], '-');
    }
    $postData['slug'] = substr($postData['slug'], 0, 100);
    if (Post::where('slug', $postData['slug'])->first()) {
      return response()->json(['error' => 'This Slug is already being used by another post'], 400);
    }

    $postData['user_id'] = $user->id;
    /**
     * @var Post $post
     */
    $post = Post::create($postData);

    if (!$post) {
      Log::warning("PostController->store: Failed saving to the DB.");
      throw new FailedRequestDBException("Failed saving to the DB");
    }

    if ($post->image_path && $imageCounter) {
      try {
        $images = Image::where("attached_to_post", true)->where("path", $post->image_path)->get();
        if (!$images) {
          Log::warning("ImageController->store: images from the directory {$post->image_path} were not found in the DB");
          throw new RecordsNotFoundException("images from the directory {$post->image_path} were not found in the DB");
        }

        foreach ($images as $image) {
          /**
           * @var Image $image
           */
          $image->post_id = $post->id;
          $image->save();
        }
      } catch (Exception $e) {
        $clearPost = $post->delete();
        Log::error("ImageController->store: Failed to attach images to the created post. Clear an incorrect post: " + var_export($clearPost, true));
        throw $e;
      }
    }

    Log::info("Post #{$post->id} has been created by user #{$user->id}");
    return response()->json($post->id, 200);
  }

  /**
   * Updates the post
   *
   * @param Request $request
   * @param Post $post
   * @return \Illuminate\Http\Response
   */
  public function update(Request $request, Post $post)
  {
    // return response()->json(["error" => "Test error"], 500);
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    $postData['user_id'] = $user->id;

    $postData = $request->only('title', 'slug', 'excerpt_raw', 'excerpt_html', 'content_raw', 'content_html', 'is_published', 'image_path');
    if (!$postData['slug']) {
      $postData['slug'] = Str::slug($postData['title'], '-');
    }
    $postData['slug'] = substr($postData['slug'], 0, 100);
    if (Post::where('slug', $postData['slug'])->where('id', '<>', $post->id)->first()) {
      return response()->json(['error' => 'This Slug is already being used by another post'], 400);
    }

    if (!$post->update($postData)) {
      Log::warning("PostController->update: Failed to update post #{$post->id} to the DB");
      throw new FailedRequestDBException("Failed to update post #{$post->id} to the DB");
    }

    Log::info("PostController->update: post #{$post->id} updated successfully");
    $images = $post->images()->get();
    return response()->json(['post' => $post, 'images' => $images ? ImageResource::collection($images) : []], 200);
  }

  /**
   * Deletes a post and its associated images
   *
   * @param Request $request
   * @param Post $post
   * @return \Illuminate\Http\Response
   */
  public function destroy(Request $request, Post $post)
  {
    // return response()->json(["error" => "Test error"], 500);
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    if ($post->image_path) {
      $images = $post->images;
      // Log::info("PostController->destroy({$post->id})", [$images, $images->count()]);

      if ($images && $images->count()) {
        if (!$images->map->delete()) {
          Log::error("PostController->destroy({$post->id}): Failed deleting DB records of images from directory {$post->image_path}");
          throw new FailedRequestDBException("Failed deleting DB records of of images from directory {$post->image_path}");
        }
      }

      if (!Storage::disk('public')->exists($post->image_path)) {
        Log::error("PostController->destroy({$post->id}): directory {$post->image_path} were not found");
        // throw new DirectoryNotFoundException("Directory {$post->image_path} not found", 404);
      } else {
        if (!Storage::disk('public')->deleteDirectory($post->image_path)) {
          Log::error("PostController->destroy({$post->id}): Failed to deleting directory {$post->image_path}");
          throw new FailedDeletingDirectoryException("Failed to deleting directory {$post->image_path}");
        }
      }
    }

    if ($post->tags_count) {
      if (!$post->tags()->detach()) {
        Log::error("PostController->destroy({$post->id}): Failed deleting entries about related tags of post");
        throw new FailedRequestDBException("Failed deleting entries about related tags of post #{$post->id}");
      }
    }

    if (!$post->delete()) {
      Log::error("PostController->destroy({$post->id}): Failed deleting DB record of post");
      throw new FailedRequestDBException("Failed deleting DB records of post #{$post->id}");
    }

    Log::info("Post #{$post->id} has been deleted by the {$user->name} #{$user->id}");
    return response()->json(["Post #{$post->id} has been successfully deleted"], 200);
  }
}
