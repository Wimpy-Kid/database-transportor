<?php

namespace CherryLu\DatabaseTransportor\Console;

use App\Transportors\Transportor;
use Illuminate\Console\Command;

class TransportCommand extends Command
{
    use Config;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transport {--class=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the commands to transport data';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if ( $this->option('class') ) {
            $transportor = $this->baseNameSpace . '\\' . $this->option('class');
            if ( class_exists($transportor) ) {
                $this->laravel->make($transportor)->transport();
            }
        } else {
            $mainTansportor = $this->baseNameSpace . '\\' . (pathinfo($this->mainTransportFileName)['filename']);
            if ( class_exists($mainTansportor) ) {
                $this->laravel->make($mainTansportor)->transport();
            }
        }

    }

}
