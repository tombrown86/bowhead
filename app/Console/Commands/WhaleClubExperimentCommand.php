<?php

namespace Bowhead\Console\Commands;

use Bowhead\Console\Kernel;
use Bowhead\Traits\CandleMap;
use Bowhead\Traits\OHLC;
use Illuminate\Console\Command;
use Bowhead\Traits\Pivots;
use Bowhead\Traits\Signals;
use Bowhead\Traits\Strategies;
use Bowhead\Util;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use AndreasGlaser\PPC\PPC; // https://github.com/andreas-glaser/poloniex-php-client

/**
 * Class ExampleCommand
 * @package Bowhead\Console\Commands
 */

class WhaleClubExperimentCommand extends Command {

	use Signals,
	 Strategies,
	 CandleMap,
	 OHLC,
	 Pivots; // add our traits

	protected $name = 'bowhead:wc_experiment';

	protected $wc;

	protected $positions;

	protected $positions_time;

	protected $indicator_positions;

	protected $description = '';
	protected $order_cooloff;
	protected $last_order_bounds = [];

	public function shutdown() {
		if (!is_array($this->indicator_positions)) {
			return 0;
		}
		foreach ($this->indicator_positions as $key => $val) {
			echo "closing $key - $val\n";
			$this->wc->positionClose($val);
		}
		return 0;
	}

	public function doColor($val) {
		if ($val == 0) {
			return 'none';
		}
		if ($val == 1) {
			return 'green';
		}
		if ($val == -1) {
			return 'magenta';
		}
		return 'none';
	}

	/**
	 * @return null
	 *
	 *  this is the part of the command that executes.
	 */
	public function handle() {
		echo "PRESS 'q' TO QUIT AND CLOSE ALL POSITIONS\n\n\n";
		stream_set_blocking(STDIN, 0);

		$instruments = ['EUR_USD'];
		$util = new Util\BrokersUtil();
		$wc = new Util\Whaleclub($this->instrument);
		$console = new \Bowhead\Util\Console();
		$indicators = new \Bowhead\Util\Indicators();

		$cand = new Util\Candles();
		$ind = new Util\Indicators();

		$this->wc = $wc;
		$last_candle_times = [];
		$skipped = 0;

		while (1) {
			if (ord(fgetc(STDIN)) == 113) { // try to catch keypress 'q'
				echo "QUIT detected...";
				return null;
			}


			$skip_weekends = TRUE;

			echo "\n";

			foreach (['1m', '5m', '15m', '30m', '1h']  as $interval) {
				$now = time();
				$now_date = date('Y-m-d H:i:s', $now);

                                $secs_since_market_open = $now - strtotime(date('Y-m-d 22:00:00', date('w', $now)=="0" ? strtotime('today', $now) : strtotime('last Sunday', $now)));

				echo "-----------------\nInterval $interval (now: $now [$now_date])\n";
				if($skip_weekends) {
					echo "\$secs_since_market_open: $secs_since_market_open\n";
				}
                               // if skipping weekends, get max num of periods since end of weekend
				//TODO: Maybe move the following to OHLC class
                               if ($interval == '1m') {
                                       $periods_to_get = $skip_weekends ? min(floor($secs_since_market_open / 60), 365) : 365;
                                       $max_period = 60 * 6;
                                       $max_avg_period = 75;
                                       $interval_secs = 60;
                                       if ($periods_to_get < 300) { // make sure there is a long enough range
                                               echo "not long enough since weekend for $interval (can only get $periods_to_get periods)\n";
                                               continue;
                                       }
                               }
                               if ($interval == '5m') {
                                       $periods_to_get = $skip_weekends ? min(floor($secs_since_market_open / (60 * 5)), 365) : 365;
                                       $max_period = 60 * 25;
                                       $max_avg_period = 60 * 8;
                                       $interval_secs = 5 * 60;
                                       if ($periods_to_get < 200) { // make sure there is a long enough range
                                               echo "not long enough since weekend for $interval (can only get $periods_to_get periods)\n";
                                               continue;
                                       }
                               }
                               if ($interval == '15m') {
                                       $periods_to_get = $skip_weekends ? min(floor($secs_since_market_open / (60 * 15)), 365) : 365;
                                       $max_period = 60 * 25;
                                       $max_avg_period = 60 * 20;
                                       $interval_secs = 15 * 60;
                                       if ($periods_to_get < 70) { // make sure there is a long enough range
                                               echo "not long enough since weekend for $interval (can only get $periods_to_get periods)\n";
                                               continue;
                                       }
                               }
                               if ($interval == '30m') {
                                       $periods_to_get = $skip_weekends ? min(floor($secs_since_market_open / (60 * 30)), 365) : 365;
                                       $max_period = 60 * 50;
                                       $max_avg_period = 60 * 40;
                                       $interval_secs = 30 * 60;
                                       if ($periods_to_get < 50) { // make sure there is a long enough range
                                               echo "not long enough since weekend for $interval (can only get $periods_to_get periods)\n";
                                               continue;
                                       }
                               }
                               if ($interval == '1h') {
                                       $periods_to_get = $skip_weekends ? min(floor($secs_since_market_open / (60 * 60)), 365) : 365;
                                       $max_period = 60 * 120;
                                       $max_avg_period = 60 * 70;
				       $interval_secs = 60 * 60;
                                       if ($periods_to_get < 40) { // make sure there is a long enough range
                                               echo "not long enough since weekend for $interval (can only get $periods_to_get periods)\n";
                                               continue;
                                       }
                               }

        			foreach ($instruments as $instrument) {
					if(isset($last_candle_times[$interval][$instrument]) && ($now - $last_candle_times[$interval][$instrument]) < $interval_secs) {
						echo "$instrument: ... wait another ".($interval_secs - ($now - $last_candle_times[$interval][$instrument]))." secs before getting $interval data (since last candle time was: ".($last_candle_times[$interval][$instrument] . date(' Y-m-d H:i:s', $last_candle_times[$interval][$instrument])).")\n";
						continue;
					}

					echo "$instrument: get $periods_to_get $interval periods for NOW ($now [$now_date])\n";

					$data = $this->getRecentData($instrument, $periods_to_get, false, date('H'), $interval, false, $now, false);
	                                if (count($data['periods']) < $periods_to_get) {
	                                        $skipped ++;
	                                        echo "$instrument: !!!!!!!!!!!!!!! Only periods ".count($data['periods'])." (less than $periods_to_get) returned for $interval! at time: $now [$now_date] \n";
	                                        continue;
	                                }
	                                if (max($data['periods']) > $max_period) {
                                	        $skipped ++;
                        	                echo "$instrument: !!!!!!!!!!!!!!! Skipping, max period was " . max($data['periods']) . " (greater than ".$max_period.") for $interval! at time: $now [$now_date]  \n";
        	                                continue;
	                                }
                                        if ((array_sum($data['periods']) / count($data['periods'])) > $max_avg_period) {
                                                $skipped ++;
                                                echo "$instrument: !!!!!!!!!!!!!!! Skipping, average period was " . (array_sum($data['periods']) / count($data['periods'])) . " (greater than ".$max_avg_period.") for $interval! at time: $now [$now_date]  \n";
                                                continue;
                                        }


					// Note time of most recent candle... so we know how long to "sleep" for, for this interval + instrument
					$last_candle_times[$interval][$instrument] = max($data['date']);

					$candles = $cand->allCandles($instrument, $data);
					$indicators = $ind->allSignals($instrument, $data);

					echo '.. ask terry..';
					$result = $this->check_terry_knowledge($instrument, $indicators, $candles, $interval);

					$direction = 0;
					if ($result['signal'] == 'long') {
						$direction = 1;
						echo "$instrument ($interval): Found long signal... " . print_r($result, 1);
					} elseif ($result['signal'] == 'short') {
						$direction = -1;
						echo "$instrument ($interval): Found short signal... " . print_r($result, 1);
					} else {
						echo '..no action'."\n";
					}

					if ($direction != 0) {
						$price = $wc->getPrice(str_replace(['/', '_'], '-', $instrument));
						$current_price = $price['price'];

						if (isset($this->last_order_bounds[$instrument]) && $current_price > $this->last_order_bounds[$instrument][0] && $current_price < $this->last_order_bounds[$instrument][1]) {
							echo ", strong signal but current price $current_price within bounds of last order (" . $this->last_order_bounds[$instrument][0] . " - " . $this->last_order_bounds[$instrument][1] . ")..\n";
						} else {
							echo ", Strong signal! .. current price found: $current_price..\n\n";
							$this->last_order_bounds[$instrument] = null;

							if (is_numeric($current_price) && $current_price > 0) {
								if ($direction < 0) {
									echo "$instrument ($interval) at $now [$now_date]:   It's happening, going SHORT..\n";

									$console->buzzer();
									$levereage = 222;
									list($stop_loss, $take_profit) = $this->get_bounds($result['bounds_method'], $data, FALSE, $current_price, $levereage);

									$order = [
										'direction' => 'short'
										, 'market' => str_replace(['/', '_'], '-', $instrument)
										, 'leverage' => 222
										, 'stop_loss' => $stop_loss
										, 'take_profit' => $take_profit
										, 'stop_loss_trailing' => true
										, 'size' => 2.22
											#	, 'entry_price' => $current_price
									];
									print_r($order);
									$position = $wc->positionNew($order);
									$console->colorize("\n$instrument ($interval):: OPENED NEW SHORT POSIITION");
									print_r($position);
									if (isset($position['entered_at'])) {
										$this->last_order_bounds[$instrument] = [$take_profit, $stop_loss];
									}
								}
								if ($direction > 0) {
									echo "$instrument ($interval) at $now [$now_date]:   It's happening, going LONG..\n";

									$levereage = 222;
									list($stop_loss, $take_profit) = $this->get_bounds(/*$result['bounds_method']*/'perc_20_20', $data, TRUE, $current_price, $levereage);

									$console->buzzer();
									$order = [
										'direction' => 'long'
										, 'market' => str_replace(['/', '_'], '-', $instrument)
										, 'leverage' => 222
										, 'stop_loss' => $stop_loss
										, 'take_profit' => $take_profit
										, 'stop_loss_trailing' => true
										, 'size' => 2.22
											#        , 'entry_price' => $current_price
									];
									print_r($order);
									$position = $wc->positionNew($order);
									$console->colorize("\n$instrument ($interval):: OPENED NEW LONG POSIITION");
									print_r($position);
									if (isset($position['entered_at'])) {
										$this->last_order_bounds[$instrument] = [$stop_loss, $take_profit];
									}
								}
								if (isset($position['entered_at'])) {
									echo "$instrument ($interval):: Created position..\n\n... no new positions until outside bounds: " . print_r($this->last_order_bounds, 1) . "\n\n\n";
								} else {
									echo "$instrument ($interval):: Position not created...! \n";
								}
							}
						}
					}
				}
			}
			sleep(1);
		}
	}

}
