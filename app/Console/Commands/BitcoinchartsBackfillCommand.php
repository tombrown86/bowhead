<?php

namespace Bowhead\Console\Commands;

use Bowhead\Util\Console;
use Illuminate\Console\Command;
use Bowhead\Util;
use Bowhead\Traits\OHLC;

class BitcoinchartsBackfillCommand extends Command
{
    use OHLC;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bowhead:bitcoincharts_backfill';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill data';

    /**
     * @var currency pairs
     */
    protected $instrument;

    /**
     * @var
     */
    protected $console;

    /**
     * @var current book
     */
    public $book;

    /**
     * @var array
     */
    public $channels = array();

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->console = $util = new Console();
        #\Cache::flush();
        #\DB::insert("DELETE FROM orca_bitfinex_ohlc WHERE instrument = 'BTC/USD'");
$c=0;
$enddate = strtotime('2018-01-27 23:00:00');
$date = strtotime('2017-12-01');
$bad=0;
		for($i=$date; $i<$enddate; $i=strtotime('+1 day', $i)) {
			$date = date('Y-m-d', $i);
			$dateend = date('Y-m-d', strtotime('+1 day',$i));
			$data = file_get_contents('https://bitcoincharts.com/charts/chart.json?m=bitstampUSD&SubmitButton=Draw&r=1&i=1-min&c=1&s='.$date.'&e='.$dateend.'&Prev=&Next=&t=S&b=&a1=&m1=10&a2=&m2=25&x=0&i1=&i2=&i3=&i4=&v=1&cv=0&ps=0&l=0&p=0&');
			$data = json_decode($data);
			
			
			$wasd = null;
			foreach($data as $d) {
				if($d[1] > 30000 || $d[3] > 30000 || $d[4] > 30000 || $d[2] > 30000) {
					print_r($d);
					$wasd[0] = $d[0];
					$d = $wasd;
					$bad++;
					echo "\n$bad bad (".date('Y-m-d H:i:s', ($d[0])).")\n";
				}
				$wasd = $d;
				
				$row = [
					'ctime' => date('Y-m-d H:i:s', ($d[0]))
					, 'timeid' => date('YmdHis', ($d[0]))
					, 'open' => ($d[1])
					, 'high' => ($d[2])
					, 'low' => ($d[3])
					, 'close' => ($d[4])
					, 'volume' => (int)$d[5]
					, 'volume2' => (int)$d[6]
//					, 'weighted_price' => $d[7]
				];
//				print_r($row);die;
				$this->markOHLC($row, 'backfill');
			}
		}
		
echo $c;
    }
}
