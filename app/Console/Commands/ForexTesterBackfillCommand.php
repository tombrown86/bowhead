<?php

namespace Bowhead\Console\Commands;

use Bowhead\Util\Console;
use Illuminate\Console\Command;
use Bowhead\Util;
use Bowhead\Traits\OHLC;

class ForexTesterBackfillCommand extends Command {

	use OHLC;

	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'bowhead:forextester_backfill';

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
		// data from :  http://www.forexrate.co.uk/forexhistoricaldata.php

		foreach (glob('/home/terry/forexdata/*.csv') as $path) {
			$handle = fopen($path, "r");
			$filename = basename($path);
			$time = strtotime(substr($filename, 0, 4) . '-'.substr($filename, 4, 2) . '-'.substr($filename, 6, 2));

			echo "\n\n\nFilename: $filename \n--------------------\n";

			while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
				$datetime = $time + ($data[0]/1000);
				$date = date('Y-m-d H:i:s', $datetime);
				if($datetime < strtotime('2017-12-11')) {
					continue;
				}
//				if($datetime > strtotime('2017-03-25 23:00:00')) {
//				if($datetime < strtotime('2017-03-26 00:00:00') || $datetime > strtotime('2017-03-26 03:00:00')) {
					echo "$date $datetime \n";
						$row = [
							'ctime' => $date
							, 'timeid' => date('YmdHis', ($datetime))
							, 'open' => ($data[1] + $data[6]) / 2
							, 'high' => ($data[2] + $data[7]) / 2
							, 'low' => ($data[3] + $data[8]) / 2
							, 'close' => ($data[4] + $data[9]) / 2
							, 'volume' => 0
							, 'volume2' => 0
	//					, 'weighted_price' => $d[7]
						];
//						print_r($row);die;
						$this->markOHLC($row, 'raw', 'EUR/USD');
//				}
//				}
			}
		}
	}

}
