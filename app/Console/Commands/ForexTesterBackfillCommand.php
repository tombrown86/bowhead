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
		ini_set('memory_limit', '4G');
		$this->console = $util = new Console();
		// data from :  http://www.forexrate.co.uk/forexhistoricaldata.php
#		foreach (glob('/home/terry/forexdata/*.csv') as $path) {
#			$handle = fopen($path, "r");
#			$filename = basename($path);
#			$time = strtotime(substr($filename, 0, 4) . '-'.substr($filename, 4, 2) . '-'.substr($filename, 6, 2));
#
#			echo "\n\n\nFilename: $filename \n--------------------\n";
#
#			while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
#				$datetime = $time + ($data[0]/1000);
#				$date = date('Y-m-d H:i:s', $datetime);
#				if($datetime < strtotime('2017-12-11')) {
#					continue;
#				}
//				if($datetime > strtotime('2017-03-25 23:00:00')) {
//				if($datetime < strtotime('2017-03-26 00:00:00') || $datetime > strtotime('2017-03-26 03:00:00')) {


		for ($year = 2015; $year < 2019; $year++) {
			for ($week = 1; $week < 54; $week++) {
				$path = '/home/tom/forexdata/fxcm/csv/' . $year . '_' . $week . '.csv';
				if (!file_exists($path)) {
					echo "File does not exist ($path).. just skipping..";
				} else {
					$handle = fopen($path, "r");
					$data = [];
					while (($line = fgets($handle)) !== false) {
						$line = str_replace("\x00", '', $line); // clear the weird NULL chars from this data
						preg_match('/(\d\d\/\d\d\/\d\d\d\d \d\d\:\d\d\:\d\d)\.(\d*),([\d\.]*),([\d\.]*)/i', $line . "\n", $matches);
						if (count($matches) == 5) {
							$date_str = date('Y-m-d H:i:00', strtotime($matches[1]));
							$data[strtotime($date_str)][] = [$matches[3], trim($matches[4])];
						}// just skip lines which don't match... (empty lines or heading line?)
					}
					fclose($handle);

					foreach ($data as $time => $bas) {
						$h = $c = 0;
						$l = 99999999;
						$o = ($bas[0][0] + $bas[0][1]) / 2;
						foreach ($bas as $i => $ba) {
							$price = ($bas[$i][0] + $bas[$i][1]) / 2;
							$h = $price > $h ? $price : $h;
							$l = $price < $l ? $price : $l;
							$c = $price;
						}

						$date = date('Y-m-d H:i:s', $time);
						echo "$date ($time) \n";
						$row = [
							'ctime' => $date
							, 'timeid' => date('YmdHis', ($time))
							, 'open' => $o
							, 'high' => $h
							, 'low' => $l
							, 'close' => $c
							, 'volume' => 0
							, 'volume2' => 0
						];
						//						print_r($row);die;
						$this->markOHLC($row, 'raw', 'GBP/JPY');
					}
				}
			}
		}
	}

}
