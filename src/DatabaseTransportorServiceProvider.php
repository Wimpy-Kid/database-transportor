<?php

namespace CherryLu\DatabaseTransportor;

use Illuminate\Support\ServiceProvider;

class DatabaseTransportorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot() {

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\CreateCommand::class,
                Console\TransportCommand::class,
                
            ]);
        }
        
    }

}
