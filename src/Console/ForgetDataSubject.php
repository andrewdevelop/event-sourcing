<?php

namespace Core\EventSourcing\Console;

use Core\EventSourcing\Contracts\KeyRing;
use Illuminate\Console\Command;

/**
 * Executes a GDPR/CCPA erasure request via crypto-shredding: destroys the encryption
 * key of a data subject so every event tagged with their pii_id becomes permanently
 * unreadable, without mutating the append-only event log. See GDPR_CCPA.md.
 */
class ForgetDataSubject extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'es:forget {pii_id : Identifier of the data subject to forget}';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Irreversibly forget a data subject\'s personal data (GDPR/CCPA erasure via crypto-shredding).';

    public function __construct(protected KeyRing $keys)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        $pii_id = $this->argument('pii_id');

        $this->keys->forget($pii_id);

        $this->info("  Forgotten data subject: {$pii_id}");
    }
}
