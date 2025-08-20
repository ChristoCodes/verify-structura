<?php

namespace App\Providers;

use AcademicObligations\ThesisCore\Tracking\clients\FilesServiceClient;
use AcademicObligations\ThesisCore\Tracking\interface\FilesServiceClientInterface;
use AcademicObligations\ThesisCore\Tracking\InternalEvents\Events\ThesisBasicDataUpdatedEvent;
use AcademicObligations\ThesisCore\Tracking\InternalEvents\Listeners\ThesisBasicUpdatedTrackingListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

  /**
   * Register any application services.
   */
  public function register()
  {
    $this->app->bind(FilesServiceClientInterface::class, FilesServiceClient::class);
  }

  /**
   * Bootstrap any application services.
   */
  public function boot()
  {
    Event::listen(
      ThesisBasicDataUpdatedEvent::class,
      [ThesisBasicUpdatedTrackingListener::class, 'handle']
    );
  }
}
