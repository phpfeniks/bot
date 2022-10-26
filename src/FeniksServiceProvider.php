<?php

namespace Feniks\Bot;

use Illuminate\Support\ServiceProvider;

class FeniksServiceProvider extends ServiceProvider
{
  public function register()
  {

  }

  public function boot()
  {
    // Register the command if we are using the application via the CLI
    if ($this->app->runningInConsole()) {
      $this->commands([
        RunFeniksBot::class,
      ]);
    }
  }
}