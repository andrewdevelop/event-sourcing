<?php 

namespace Core\EventSourcing\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Core\EventSourcing\Contracts\EventStore;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\DomainEvent;
use Core\EventSourcing\Pii\PiiAnonymizer;


class ReplayEvents extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'es:replay 
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Regenerate read models from event store.';

    /**
     * Create a new command instance.
     */
    public function __construct(
        protected EventDispatcher $dispatcher,
        protected Kernel $console,
        protected EventStore|null $event_store = null,
        protected PiiAnonymizer|null $anonymizer = null,
    )
    {
    	parent::__construct();
    }


    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
    	// Increase maximum execution time, This can take a while
    	ini_set('max_execution_time', 0);

        $force = $this->option('force');
        $quiet = $this->option('quiet');

    	if (!$this->event_store) {
    		$this->error("  Cannot recreate read database, because no event store installed.");
    	}

    	$params = ($force != null) ? ['--force' => true] : [];
        $this->console->call('migrate:reset', $params);
        $this->console->call('migrate', $params);
        $this->replay($quiet);
    }


    public function replay(bool $quiet): void
    {
        $events = $this->event_store->loadAll();

        $this->line("");

        foreach ($events as $data) {
            if (!$quiet) {
                $this->info('  Replay event: '.$data->name);
                $this->line('  ID: '.$data->name.' '.$data->id.' Time: '.$data->created_at);
                $this->line("  Aggregate: ".$data->aggregate_id. " - ".$data->aggregate_type);
                $this->line("");
            }
            $event = new DomainEvent((array) $data);
            // Decrypt PII fields for subjects who haven't been forgotten, so rebuilt
            // projections see real data; no-op if the event carries no PII or the
            // subject's key is already destroyed. See GDPR_CCPA.md.
            $this->anonymizer?->open($event);
            try {
            	$this->dispatcher->replay($event);
            } catch (Exception $e) {
                $this->error("  Error: ".$e->getMessage(), 'vvv');
                $this->line("");
            }
        }

    }    
}