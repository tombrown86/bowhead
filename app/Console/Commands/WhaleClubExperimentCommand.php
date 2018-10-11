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
		ini_set('memory_limit', '2G');
		date_default_timezone_set('UTC');

		echo "PRESS 'q' TO QUIT AND CLOSE ALL POSITIONS\n\n\n";
		stream_set_blocking(STDIN, 0);

		$instruments = ['EUR_USD', 'EUR_GBP', 'GBP_USD', /*'GBP_JPY'*/];
		$util = new Util\BrokersUtil();
		$wc = [];
		$console = new \Bowhead\Util\Console();

		//$cand = new Util\Candles();
		$ind = new Util\Indicators();

		$this->wc = $wc;
		$last_candle_times = [];
		$skipped = 0;
		$skip_weekends = FALSE;

		$data_min_datetime = '2018-10-08 11:30:02'; #  '0000-00-00 00:00:00';
		$leverage = 222;
//		$spread = ['EUR_USD' => 0.02, 'EUR_GBP' => 0.03, 'GBP_USD' => 0.06,/* 'GBP_JPY' => */];

		foreach ($instruments as $instrument) {
			$wc[$instrument] = new Util\Whaleclub($instrument);
		}

		while (1) {
			if (ord(fgetc(STDIN)) == 113) { // try to catch keypress 'q'
				echo "QUIT detected...";
				return null;
			}
			echo "\n -----------------------------------------------------\n";

			foreach ($this->positions as $instrument => $poss) {
				foreach ($poss as $i => $pos) {
					sleep(10);
					$terry_tip = $pos['terry_tip'];
					$p = $wc[$instrument]->positionGet($pos['id']);
					if (isset($p['profit'])) {
						$inf = ['pos now' => $p, 'pos open' => $pos, 'terry tip' => $terry_tip];
						file_put_contents('/home/tom/results/wc_experiment_' . date('Y-m-d'), "\n\nCLOSED:\n" . print_r($inf, 1) . "\n-----------------------", FILE_APPEND);
						if ($p['profit'] >= 0) {
							$this->mailer('WIN!', $inf);
						} else {
							$this->mailer('LOSE!', $inf);
						}


						$wc_result = new \Bowhead\Models\wc_trade();

						foreach (['slug', 'direction', 'market', 'leverage', 'type', 'state', 'size', 'margin_size', 'entry_price', 'take_profit', 'stop_loss', 'close_price', 'liquidation_price', 'profit', 'financing', 'close_reason', 'created_at', 'entered_at', 'closed_at', 'currency', 'err',] as $field) {
							if (isset($p[$field])) {
								$wc_result->$field = $p[$field];
							}
						}

						$wc_result->trailing = @$p['stop_loss_trailing']['set'];
						$wc_result->trailing_distance = @$p['stop_loss_trailing']['distance'];
						$wc_result->terry_bounds_method = $terry_tip['bounds_method'];
						$wc_result->terry_signal = $terry_tip['signal'];
						$wc_result->terry_info = $terry_tip['info'];
						$wc_result->terry_strategy_knowledge_id = $terry_tip['knowledge_row']->id;
						$wc_result->current_price = $pos['current_price'];
						$wc_result->save();
						unset($this->positions[$instrument][$i]);
					}
				}
			}

			foreach (['15m', '5m', '30m', '1h', '1m'] as $interval) { // go through in order of avg profit (using avg results from training)
				$now = time();
				$now_date = date('Y-m-d H:i:s', $now);
				$secs_since_min_datetime = strtotime($now_date) - strtotime($data_min_datetime);
				$secs_since_market_open = $skip_weekends ? ($now - strtotime(date('Y-m-d 22:00:00', date('w', $now) == "0" ? strtotime('today', $now) : strtotime('last Sunday', $now)))) : PHP_INT_MAX;
				$interval_upper_limit = min($secs_since_market_open, $secs_since_min_datetime);

				echo "-----------------\nInterval $interval (now: $now [$now_date])\n";
				if ($skip_weekends) {
					echo "\$secs_since_market_open: $secs_since_market_open\n";
				}
				echo "\n\$secs_since_min_datetime: $secs_since_min_datetime (\$data_min_datetime: $data_min_datetime)\n";
				echo "\n\n\$interval_upper_limit: $interval_upper_limit\n\n";

				list($periods_to_get, $max_period, $max_avg_period, $interval_secs, $min_periods) = Strategies::get_rules_for_interval($interval, $interval_upper_limit);

				if ($skip_weekends && $periods_to_get < $min_periods) { // make sure there is a long enough range
					echo "not long enough since min date for $interval (can only get $periods_to_get periods) and we want at least $min_periods.. \n";
					continue;
				}
				if (!$periods_to_get) {
					echo "periods_to_get must be positive ($interval).. \n";
					continue;
				}


				foreach ($instruments as $instrument) {
					if (isset($last_candle_times[$interval][$instrument]) && ($now - $last_candle_times[$interval][$instrument]) < $interval_secs/* 10 secs leeway so we have time to get tick data */) {
						echo "$instrument: ... wait another " . ($interval_secs - ($now - $last_candle_times[$interval][$instrument])) . " secs before getting $interval data (since last candle time was: " . ($last_candle_times[$interval][$instrument] . date(' Y-m-d H:i:s', $last_candle_times[$interval][$instrument])) . ")\n";
						continue;
					}

					echo "$instrument: get $periods_to_get $interval periods for NOW ($now [$now_date])\n";

					$data = $this->getRecentData($instrument, $periods_to_get, false, date('H'), $interval, false, $now, false);
					if (count($data['periods']) < $periods_to_get) {
						$skipped ++;
						echo "$instrument: !!!!!!!!!!!!!!! Only periods " . count($data['periods']) . " (less than $periods_to_get) returned for $interval! at time: $now [$now_date] \n";
						continue;
					}
					if (max($data['periods']) > $max_period) {
						$skipped ++;
						echo "$instrument: !!!!!!!!!!!!!!! Skipping, max period was " . max($data['periods']) . " (greater than " . $max_period . ", found at time " . date('Y-m-d H:i:s', $data['date'][array_search(max($data['periods']), $data['periods'])]) . ") for $interval! at time: $now [$now_date]  \n";
						continue;
					}
					if ((array_sum($data['periods']) / count($data['periods'])) > $max_avg_period) {
						$skipped ++;
						echo "$instrument: !!!!!!!!!!!!!!! Skipping, average period was " . (array_sum($data['periods']) / count($data['periods'])) . " (greater than " . $max_avg_period . ") for $interval! at time: $now [$now_date]  \n";
						continue;
					}

					// Note time of most recent candle... so we know how long to "sleep" for, for this interval + instrument
					$last_candle_times[$interval][$instrument] = max($data['date']);

					$candles = $this->candle_value($data);
					$indicators = $ind->allSignals($instrument, $data);

					// not all bounds method will return profitable limits.. 
					$current_price_from_data = ($data['high'][count($data['low']) - 1] + $data['low'][count($data['low']) - 1]) / 2;
					$price = $wc[$instrument]->getPrice(str_replace(['_', '/'], '-', $instrument));
					
					echo '.. PRICE: '.PRINT_R($price, 1).'..';
					list($profitable_long_bounds_methods, $profitable_short_bounds_methods) = $this->get_profitable_bounds_methods($current_price_from_data, $data, $leverage, $price['spread'], $interval);

					echo '.. ask terry..';

//					$ins = in_array( $instrument, ['GBP_USD', 'EUR_USD']) ? $instrument.$spread[$instrument] : $instrument;
					$ins = $instrument;
					$result = $this->check_terry_knowledge3($ins, $indicators, $candles, $interval, $profitable_long_bounds_methods, $profitable_short_bounds_methods);

//					print_r($result,1);

					$direction = 0;
					if ($result['signal'] == 'long') {
						$direction = 1;
						echo "$instrument ($interval): Found long signal... " . print_r($result, 1);
					} elseif ($result['signal'] == 'short') {
						$direction = -1;
						echo "$instrument ($interval): Found short signal... " . print_r($result, 1);
					} else {
						echo '..no action' . "\n";
					}

					if ($direction != 0) {
//						$price = $wc[$instrument]->getPrice(str_replace(['_', '/'], '-', $instrument));
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
									list($stop_loss, $take_profit) = $this->get_bounds($data, FALSE, $current_price, $leverage, $result['bounds_method']);

									$order = [
										'direction' => 'short'
										, 'market' => str_replace(['/', '_'], '-', $instrument)
										, 'leverage' => $leverage
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

									list($stop_loss, $take_profit) = $this->get_bounds($data, TRUE, $current_price, $leverage, [$result['bounds_method']])[$result['bounds_method']];

									$console->buzzer();
									$order = [
										'direction' => 'long'
										, 'market' => str_replace(['/', '_'], '-', $instrument)
										, 'leverage' => $leverage
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
									file_put_contents('/home/tom/results/wc_experiment_' . date('Y-m-d'), "$instrument position created!:\n" . print_r([$order, ['position' => $position, 'terry tip' => $result]], 1) . "\n" . print_r($position, 1) . "\n-----------------------", FILE_APPEND);
									$position['terry_tip'] = $result;
									$position['current_price'] = $current_price;
									isset($this->positions[$instrument]) || $this->positions[$instrument] = [];
									$this->positions[$instrument][] = $position;
									$this->mailer($direction > 0 ? 'Going long' : 'Going short', ['position' => $position, 'terry tip' => $result]);
									echo "$instrument ($interval):: Created position..\n\n... no new positions until outside bounds: " . print_r($this->last_order_bounds, 1) . "\n\n\n";
								} else {
									file_put_contents('/home/tom/results/wc_experiment_' . date('Y-m-d'), "$instrument POSITION NOT CREATED!!!!\n" . print_r([$order, ['position' => $position, 'terry tip' => $result]], 1) . "\n" . print_r($position, 1) . "\n-----------------------", FILE_APPEND);
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
