<?php

namespace Bowhead\Console\Commands;

use Bowhead\Util\Console;
use Illuminate\Console\Command;
use Bowhead\Util;
use Bowhead\Traits\OHLC;

class HistDataBackfillCommand extends Command {

	use OHLC;

	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'bowhead:histdata_backfill';

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
	public function __construct() {
		parent::__construct();
	}

	public function handle() {
		$this->console = $util = new Console();
		#\Cache::flush();
		#\DB::insert("DELETE FROM orca_bitfinex_ohlc WHERE instrument = 'BTC/USD'");
		if (($handle = fopen("/home/terry/DAT_ASCII_EURUSD_M1_2017.csv", "r")) !== FALSE) {
			while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
				$data[0] = str_replace(' ', '', $data[0]);
				$date = substr($data[0], 0, 4) . '-'.substr($data[0], 4, 2). '-'.substr($data[0], 6, 2). ' '.substr($data[0], 8, 2). ':'.substr($data[0], 10, 2).':'.substr($data[0], 12, 2);
				
				$datetime = strtotime($date);
				
					$row = [
						'ctime' => $date
						, 'timeid' => date('YmdHis', ($datetime))
						, 'open' => ($data[1])
						, 'high' => ($data[2])
						, 'low' => ($data[3])
						, 'close' => ($data[4])
						, 'volume' => 0
						, 'volume2' => 0
//					, 'weighted_price' => $d[7]
					];
//					print_r($row);
					$this->markOHLC($row, 'backfill', 'EUR/USD');
				}
			}
	}

}
