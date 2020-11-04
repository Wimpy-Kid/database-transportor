<?php

namespace CherryLu\DatabaseTransportor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateCommand extends Command
{
    use Config;
    
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:transportor {filename}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the commands to create a transpotor';

    protected $createTemp = 'CreateTemp.temp';

    protected $mainTransportorTemp = 'TransportorTemp.temp';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $file_name = ucfirst(Str::camel(trim($this->argument('filename'), ' /\\')));
        
        $file_info = $this->checkOrCreateFile($file_name);

        $template = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $this->createTemp);

        $created_namespace = trim($file_info['dirname'], ' \\/.');
        
        $template = str_replace([
            '%NAMESPACE%',
            '%CLASSNAME%'
        ], [
            $this->baseNameSpace . ($created_namespace ? ('\\' . $created_namespace) : ''),
            $file_info['filename']
        ], $template);

        $created_filename = $this->baseDir . DIRECTORY_SEPARATOR . $file_info['dirname'] . DIRECTORY_SEPARATOR . $file_info['filename'] . '.php';
        if ( is_file($created_filename) ) {
            return $this->error('该文件已存在！');
        }
        file_put_contents($created_filename, $template);
        
    }

    protected function checkOrCreateFile($file_name) {

        $file_info = pathinfo($file_name);
        $this->mkdirs($this->baseDir . DIRECTORY_SEPARATOR . $file_info['dirname']);

        if ( !is_file($this->baseDir . DIRECTORY_SEPARATOR . $this->mainTransportFileName) ) {
            $template = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $this->mainTransportorTemp);
            $template = str_replace('%NAMESPACE%', $this->baseNameSpace, $template);

            file_put_contents(
                $this->baseDir . DIRECTORY_SEPARATOR . $this->mainTransportFileName,
                $template
            );
        }

        return $file_info;
    }

    protected function mkdirs($dir, $mode = 0777) {
        if (is_dir($dir) || @mkdir($dir, $mode)) return true;
        if (!$this->mkdirs(dirname($dir), $mode)) return false;
        return @mkdir($dir, $mode);
    }
    
}
