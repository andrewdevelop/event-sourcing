<?php 

namespace Core\EventSourcing\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Core\EventSourcing\Contracts\EventStore;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\DomainEvent;


class ReplayEvents extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'es:replay';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Regenerate read models from event store.';    

    /**
     * Event store instance.
     * @var [type]
     */
    protected $event_store;

    /**
     * The Artisan instance.
     * @var [type]
     */
    protected $console;

    /**
     * Create a new command instance.
     */
    public function __construct(EventDispatcher $dispatcher, Kernel $console, EventStore $event_store = null)
    {
    	parent::__construct();
        $this->dispatcher = $dispatcher;
        $this->console = $console;
        $this->event_store = $event_store;
    }


    /**
     * Execute the console command.
     * @return mixed
     */
    public function handle()
    {
    	// Increase maximum execution time, This can take a while
    	ini_set('max_execution_time', 0);

    	if (!$this->event_store) {
    		$this->error("  Cannot recreate read database, because no event store installed.");
    	}

        $this->console->call('migrate:reset');
        $this->console->call('migrate');
        $this->replay();
    }


    public function replay()
    {
        $events = $this->event_store->loadAll();

        $this->line("");

        foreach ($events as $data) {
            $this->info('  Replay event: '.$data->name);
            $this->line('  ID: '.$data->name.' '.$data->id.' Time: '.$data->created_at);
            $this->line("  Aggregate: ".$data->aggregate_id. " - ".$data->aggregate_type);
            
            $event = new DomainEvent((array) $data);

            try {
            	$this->dispatcher->replay($event);
            } catch (Exception $e) {
                $this->error("  Error: ".$e->getMessage());
            }
            
            $this->line("");
        }

    }    
}