<?php

namespace Bowhead\Console\Commands;
require_once "Mail.php";

use Bowhead\Console\Kernel;
use Bowhead\Traits\CandleMap;
use Bowhead\Traits\OHLC;
use Illuminate\Console\Command;
use Bowhead\Traits\Pivots;
use Bowhead\Traits\Signals;
use Bowhead\Traits\Strategies;
use Bowhead\Traits\Mailer;
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
	Pivots,
	Mailer; 

	protected $name = 'bowhead:wc_experiment';

	protected $wc;

	protected $positions = [];

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
		ini_set('memory_limit','2G');
		echo "PRESS 'q' TO QUIT AND CLOSE ALL POSITIONS\n\n\n";
		stream_set_blocking(STDIN, 0);

		$instruments = ['EUR_USD', 'EUR_GBP'];
		$util = new Util\BrokersUtil();
		$wc = [];
		$console = new \Bowhead\Util\Console();
		$indicators = new \Bowhead\Util\Indicators();

		//$cand = new Util\Candles();
		$ind = new Util\Indicators();

		$this->wc = $wc;
		$last_candle_times = [];
		$skipped = 0;
		$skip_weekends = FALSE;

		foreach($instruments as $instrument) {
			$wc[$instrument] = new Util\Whaleclub($instrument);
		}

		while (1) {
			if (ord(fgetc(STDIN)) == 113) { // try to catch keypress 'q'
				echo "QUIT detected...";
				return null;
			}
			echo "\n -----------------------------------------------------\n";

			foreach($this->positions as $i=>$pos) {
				sleep(10);
				$p = $wc[$instrument]->positionGet($pos['id']);
				if(isset($p['profit'])) {
					file_put_contents('/home/tom/results/wc_experiment_closed '.date('Ymd His'), print_r($pos, 1) . "\n".print_r($p, 1) . "\n-----------------------", FILE_APPEND);
					if($p['profit'] >= 0) {
						$this->mailer('WIN!', [$p, $pos]);
					} else {
						$this->mailer('LOSE!', [$p, $pos]);
					}
					unset($this->positions[$i]);
				}
			}

			foreach (['1m', '5m', '15m', '30m', '1h']  as $interval) {
				$now = time();
				$now_date = date('Y-m-d H:i:s', $now);
                                $secs_since_market_open = $now - strtotime(date('Y-m-d 22:00:00', date('w', $now)=="0" ? strtotime('today', $now) : strtotime('last Sunday', $now)));

				echo "-----------------\nInterval $interval (now: $now [$now_date])\n";
				if($skip_weekends) {
					echo "\$secs_since_market_open: $secs_since_market_open\n";
				}

				list($periods_to_get, $max_period, $max_avg_period, $interval_secs, $min_periods) = Strategies::get_rules_for_interval($interval, $secs_since_market_open);

                                if ($skip_weekends && $periods_to_get < $min_periods) { // make sure there is a long enough range
                                        echo "not long enough since weekend for $interval (can only get $periods_to_get periods) and we want at least $min_periods.. \n";
                                        continue;
                                }


        			foreach ($instruments as $instrument) {
					if(isset($last_candle_times[$interval][$instrument]) && ($now - $last_candle_times[$interval][$instrument]) < $interval_secs/*10 secs leeway so we have time to get tick data*/) {
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

					$candles = $this->candle_value($data);
					$indicators = $ind->allSignals($instrument, $data);

					echo '.. ask terry..';
					$result = $this->check_terry_knowledge($instrument, $indicators, $candles, $interval);

					print_r($result,1);

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
						$price = $wc[$instrument]->getPrice(str_replace(['/', '_'], '-', $instrument));
						$current_price = $price['price'];

						if (isset($this->last_order_bounds[$instrument]) && $current_price > $this->last_order_bounds[$instrument][0] && $current_price < $this->last_order_bounds[$instrument][1]) {
							echo ", strong signal but current price $current_price within bounds of last order (" . $this->last_order_bounds[$instrument][0] . " - " . $this->last_order_bounds[$instrument][1] . ")..\n";
						} else {
							echo ", Strong signal! .. current price found: $current_price..\n\n";
							$this->last_order_bounds[$instrument] = null;
							file_put_contents('/home/tom/results/wc_experiment_attempted_opened '.date('Ymd His'), "$instrument position open attempt!"
									. " result:".print_r($result).":\n-----------------------"
									, FILE_APPEND);
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
									$position = $wc[$instrument]->positionNew($order);
									
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
									$position = $wc[$instrument]->positionNew($order);
									$console->colorize("\n$instrument ($interval):: OPENED NEW LONG POSIITION");
									print_r($position);
									if (isset($position['entered_at'])) {
										$this->last_order_bounds[$instrument] = [$stop_loss, $take_profit];
									}
								}
								if (isset($position['entered_at'])) {
									file_put_contents('/home/tom/results/wc_experiment_opened '.date('Ymd His'), "$instrument position created!:\n".print_r($order, 1) . "\n".print_r($position, 1) . "\n-----------------------", FILE_APPEND);
									$this->positions[] = $position;
									$this->mailer($direction > 0 ? 'Going long' : 'Going short', $position);
									echo "$instrument ($interval):: Created position..\n\n... no new positions until outside bounds: " . print_r($this->last_order_bounds, 1) . "\n\n\n";
								} else {
									file_put_contents('/home/tom/results/wc_experiment_opened '.date('Ymd His'), "$instrument POSITION NOT CREATED!!!!\n". print_r($order, 1) . "\n".print_r($position, 1) . "\n-----------------------", FILE_APPEND);
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
