<?php

namespace App\Jobs;

use App\Models\Coin;
use JsonRPC\Exception\ConnectionFailureException;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletAddressCreater implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $coin;

    /**
     * Create a new job instance.
     *
     * @param Coin|null $coin
     */
    public function __construct(Coin $coin = null)
    {
        $this->coin = $coin;
        $this->queue = "default";
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!$this->coin) {
            $active_coin_ids = DB::table('jobs')->where('id', '!=', $this->job->getJobId())->where('queue', $this->queue)->get('payload')->map(function ($v) {
                return unserialize(json_decode($v->payload, true)["data"]["command"])->coin->id;
            });

            Coin::whereNotIn('id', $active_coin_ids)
                ->where('enable', true)
                ->get()->each(function (Coin $coin) {
                    self::dispatch($coin);
                });
            return;
        }

        try {
            $client = &$this->coin->client;
            $wallets = $this->coin->wallets()->whereNull("address")->get();
            if (!$wallets->count()) {
                sleep(5);
            } else {
                $client->batch();
                foreach ($wallets as $a) {
                    $client->getnewaddress("");
                }
                $addresses = $client->send();
                $wallets->transform(function ($wallet, $i) use (&$addresses) {
                    $wallet->address = $addresses[$i];
                    return $wallet;
                });
                $wallets->each->save();
            }
            self::dispatch($this->coin)->delay(10);
        } catch (ConnectionFailureException $e) {
            Log::error("[{$this->coin->id}|{$this->coin->name}] Unable to establish a connection {$this->coin->rpc_url}");
        }
    }
}
