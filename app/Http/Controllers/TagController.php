<?php

namespace App\Http\Controllers;

use App\Exceptions\FailedRequestDBException;
use App\Http\Requests\TagRequest;
use App\Http\Resources\TagPaginatedCollection;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Log;
use Response;
use Str;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TagController extends Controller
{
  /**
   * The number of records displayed on the page
   *
   * @var integer
   */
  protected $pageLimit = 20;

  /**
   * Returns a patinated list of Tags
   *
   * @param Request $request
   * @return \Illuminate\Http\Response
   */
  public function list(Request $request)
  {
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    $tagList = Tag::orderBy('id', 'desc')->paginate($this->pageLimit)->withPath('');
    return response()->json(new TagPaginatedCollection($tagList), 200);
  }

  /**
   * Checks if the Tag name is free
   *
   * @param TagRequest $request
   * @return \Illuminate\Http\Response
   */
  public function checkNameIsFree(TagRequest $request)
  {
    $newTagData['name'] = $request->string('name')->trim();
    $newTagData['slug'] = Str::slug($newTagData['name']);
    $tagId = null;
    if ($request->has('tag_id')) {
      $tagId = $request->integer('tag_id');
    }

    $this->checkNameAndSlugUniqueness($newTagData['name'], $newTagData['slug'], $tagId);

    return response()->json(true, 200);
  }

  /**
   * Saves a new Tag
   *
   * @param TagRequest $request
   * @return \Illuminate\Http\Response
   */
  public function store(TagRequest $request)
  {
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    $newTagData['name'] = $request->string('name')->trim();
    $newTagData['slug'] = Str::slug($newTagData['name']);
    $newTagData['name_low_case'] = Str::lower($newTagData['name']);

    $this->checkNameAndSlugUniqueness($newTagData['name'], $newTagData['slug']);
    $tag = Tag::create($newTagData);
    if (!$tag) {
      Log::warning("TagController->store: Failed saving to the DB.");
      throw new FailedRequestDBException("Failed saving to the DB");
    }

    Log::info("Tag `{$tag->name}` #{$tag->id} has been created by user #{$user->id}");
    return response()->json(new TagResource($tag), 200);
  }

  /**
   * Updates the Tag
   *
   * @param TagRequest $request
   * @param Tag $tag
   * @return \Illuminate\Http\Response
   */
  public function update(TagRequest $request, Tag $tag)
  {
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    $newTagData['name'] = $request->string('name')->trim();
    $newTagData['slug'] = Str::slug($newTagData['name']);
    $newTagData['name_low_case'] = Str::lower($newTagData['name']);

    if ($tag->name === $newTagData['name']) {
      return response()->json(new TagResource($tag), 200);
    }

    $this->checkNameAndSlugUniqueness($newTagData['name'], $newTagData['slug']);
    $isUpdated = $tag->update($newTagData);
    if (!$isUpdated) {
      Log::warning("TagController->update: Failed saving to the DB.");
      throw new FailedRequestDBException("Failed saving to the DB");
    }

    Log::info("Tag `{$tag->name}` #{$tag->id} has been updated by user #{$user->id}");
    return response()->json(new TagResource($tag), 200);
  }

  /**
   * Checking the uniqueness of the Tag name and slug
   *
   * @param string $name
   * @param string $slug
   * @param integer|null $tagId
   * @return void
   */
  private function checkNameAndSlugUniqueness($name, $slug, $tagId = null)
  {
    if ($tagId) {
      $tag = Tag::where('id', '<>', $tagId)->where(function ($query) use ($name, $slug) {
        $query->where('name', $name)->orWhere('slug', $slug);
      });
    } else {
      $tag = Tag::where('name', $name)->orWhere('slug', $slug)->first();
    }

    if ($tag) {
      if ($tag->name === $name) {
        throw new ValidationException('This name is already in use');
      }
      if ($tag->slug === $slug) {
        throw new ValidationException('This slug is already in use');
      }
    }
  }

  /**
   * Deleting a Tag
   *
   * @param Request $request
   * @param Tag $tag
   * @return \Illuminate\Http\Response
   */
  public function destroy(Request $request, Tag $tag)
  {
    $user = $request->user();
    if (!$user->isAdmin()) throw new AccessDeniedHttpException('Access denied');

    if ($tag->posts_count) {
      if (!$tag->posts()->detach()) {
        Log::error("TagController->destroy({$tag->id}): Failed deleting entries about related posts of tag");
        throw new FailedRequestDBException("Failed deleting entries about related posts of tag #{$tag->id}");
      }
    }

    if (!$tag->delete()) {
      Log::error("TagController->destroy({$tag->id}): Failed deleting DB record of tag");
      throw new FailedRequestDBException("Failed deleting DB records of tag #{$tag->id}");
    }

    Log::info("Tag `{$tag->name}` #{$tag->id} has been deleted by the {$user->name} #{$user->id}");
    return response()->json(["Tag #{$tag->id} has been successfully deleted"], 200);
  }
}
