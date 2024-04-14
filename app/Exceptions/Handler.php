<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use SebastianBergmann\CodeCoverage\Util\DirectoryCouldNotBeCreatedException;
use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
  /**
   * A list of exception types with their corresponding custom log levels.
   *
   * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
   */
  protected $levels = [
    //
  ];

  /**
   * A list of the exception types that are not reported.
   *
   * @var array<int, class-string<\Throwable>>
   */
  protected $dontReport = [
    //
  ];

  /**
   * A list of the inputs that are never flashed to the session on validation exceptions.
   *
   * @var array<int, string>
   */
  protected $dontFlash = [
    'current_password',
    'password',
    'password_confirmation',
  ];

  /**
   * Register the exception handling callbacks for the application.
   *
   * @return void
   */
  public function register()
  {
    //   $this->reportable(function (AuthenticationException $e, $request) {
    //     Log::info("Handler->register->AuthenticationException callback", [$e]);
    //   // if ($request->is('api/*')) {

    //     return response()->json([
    //       'status_code' => 401,
    //       'success' => false,
    //       'message' => 'Unauthenticated'
    //     ], 401);
    //   // }
    //  });
  }

  public function render($request, Throwable $e)
  {
    if ($e instanceof AuthenticationException) {
      return $this->customApiResponse($e, 'Unauthorized', 401);
    }
    if ($e instanceof AccessDeniedHttpException) {
      return $this->customApiResponse($e, '', 403);
    }

    // An exception is thrown when the firsrOrFail() and findOrFail() methods fail
    if ($e instanceof ModelNotFoundException) {
      return $this->customApiResponse($e, $e->getMessage() || 'Record not found', 404);
    }
    if ($e instanceof RecordsNotFoundException) {
      return $this->customApiResponse($e, $e->getMessage() || 'Record not found', 404);
    }

    if ($e instanceof CannotWriteFileException) {
      return $this->customApiResponse($e, $e->getMessage() || 'Failed to write the file', 500);
    }
    if ($e instanceof DirectoryCouldNotBeCreatedException) {
      return $this->customApiResponse($e, $e->getMessage() || 'Failed to create a directory', 500);
    }


    return $this->customApiResponse($e);
  }

  private function customApiResponse($exception, $message = "", $statusCode = 0)
  {
    if (!$statusCode) {
      if (method_exists($exception, 'getStatusCode')) {
        $statusCode = $exception->getStatusCode();
      } else {
        $statusCode = 500;
      }
    }

    $response['status'] = $statusCode;

    $response['message'] = $message ? $message : $exception->getMessage();
    if (!$response['message']) {
      switch ($statusCode) {
        case 401:
          $response['message'] = 'Unauthorized';
          break;
        case 403:
          $response['message'] = 'Forbidden';
          break;
        case 404:
          $response['message'] = 'Not Found';
          break;
        case 405:
          $response['message'] = 'Method Not Allowed';
          break;
        case 422:
          $response['message'] = $exception->original['message'];
          $response['errors'] = $exception->original['errors'];
          break;
        default:
          $response['message'] = ($statusCode == 500) ? 'Whoops, looks like something went wrong' : $exception->getMessage();
          break;
      }
    }

    if (config('app.debug')) {
      $response['trace'] = $exception->getTrace();
      $response['code'] = $exception->getCode();
    }

    return response()->json($response, $statusCode);
  }
}
