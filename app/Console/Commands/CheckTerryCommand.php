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
use Bowhead\Models;

/**
 * Class ExampleCommand
 * @package Bowhead\Console\Commands
 */
ini_set('memory_limit', '8G');

class CheckTerryCommand extends Command {

	use Signals,
	 Strategies,
	 CandleMap,
	 OHLC,
	 Pivots; // add our traits

	protected $name = 'check_terry';
	protected $description = '';

	/**
	 * @return null
	 *
	 *  this is the part of the command that executes.
	 */
	public function handle() {
		$terry_strategy_knowledge = new Models\terry_strategy_knowledge();

		echo "PRESS 'q' TO QUIT AND CLOSE ALL POSITIONS\n\n\n";
		stream_set_blocking(STDIN, 0);

		$util = new Util\BrokersUtil();
		$console = new \Bowhead\Util\Console();
		$indicators = new \Bowhead\Util\Indicators();
		$cand = new Util\Candles();
		$ind = new Util\Indicators();

		$instrument = 'GBP/JPY';
		$skip_weekends = TRUE;
		$results_dir = '/home/tom/results';
		$results_filename = 'terry3_exact_70_jpy';
		$results = [];

		$strategy_open_position = [];

		$list_indicators = array('adx', 'aroonosc', 'cmo', 'sar', 'cci', 'mfi', 'obv', 'stoch', 'rsi', 'macd', 'bollingerBands', 'atr', 'er', 'hli', 'ultosc', 'willr', 'roc', 'stochrsi');

		$skipped = 0;
		$end_min = strtotime('2018-01-01 00:00:00');
		$start_min = strtotime('2017-01-01 00:00:00');

		$spread = '0.01'; // fixed for now..
		$leverage = 222;

		for ($min = $start_min; $min <= $end_min; $min += 60) {
			$min_date = date('Y-m-d H:i:s', $min);
			if ($skip_weekends &&
					((date('w', $min) == 5 && date('H') >= 20/* 22 */) || date('w', $min) == 6 || (date('w', $min) == 0 && date('H') < 22))) { // continue if market closed
				continue;
			}

			// "close" any orders which would've completed 
			foreach ($strategy_open_position as $strategy => $etime) {
				if ($etime < $min) {
					unset($strategy_open_position[$strategy]);
				}
			}

			foreach (['1m', '5m', '15m', '30m', '1h'] as $interval) {
				$secs_since_market_open = $min - strtotime(date('Y-m-d 22:00:00', date('w', $min) == "0" ? strtotime('today', $min) : strtotime('last Sunday', $min)));

				list($periods_to_get, $max_period, $max_avg_period, $interval_secs, $min_periods) = Strategies::get_rules_for_interval($interval, $secs_since_market_open);

				if ($interval != '1m' && $min % $interval_secs) {
					// only test intervals at appropriate intervals?
					continue;
				}

				if ($skip_weekends && $periods_to_get < $min_periods) { // make sure there is a long enough range
					echo "not long enough since weekend for $interval (can only get $periods_to_get periods) ... minimum we want is $min_periods \n";
					continue;
				}


				$win_or_lose_short = $win_or_lose_long = []; //keep results here as we have multiple strats to check 

				echo get_class($this) . " - $instrument: get recent data, get $periods_to_get periods of $interval data..";
				$data = $this->getRecentData($instrument, $periods_to_get, false, date('H'), $interval, false, $min, false);

				if (count($data['periods']) < $periods_to_get) {
					$skipped ++;
					echo "$instrument: !!!!!!!!!!!!!!! Only periods " . count($data['periods']) . " (less than $periods_to_get) returned for $interval! at time: $min [$min_date] \n";
					continue;
				}
				if (max($data['periods']) > $max_period) {
					$skipped ++;
					echo "$instrument: !!!!!!!!!!!!!!! Skipping, max period was " . max($data['periods']) . " (greater than " . $max_period . ") for $interval! at time: $min [$min_date]  \n";
					continue;
				}
				if ((array_sum($data['periods']) / count($data['periods'])) > $max_avg_period) {
					$skipped ++;
					echo "$instrument: !!!!!!!!!!!!!!! Skipping, average period was " . (array_sum($data['periods']) / count($data['periods'])) . " (greater than " . $max_avg_period . ") for $interval! at time: $min [$min_date]  \n";
					continue;
				}

				$current_price = ($data['high'][count($data['low']) - 1] + $data['low'][count($data['low']) - 1]) / 2;
				$candles = $this->candle_value($data);
				$indicator_results = $ind->allSignals($instrument, $data);

				$terry_result = $this->check_terry_knowledge3($instrument, $indicator_results, $candles, $interval);

				$overbought = $underbought = $direction = 0;
				if ($terry_result['signal'] == 'long') {
					$direction = 1;
					echo "$instrument ($interval): Found long signal... " ;//. print_r($terry_result, 1);
					$underbought = 1;
				} elseif ($terry_result['signal'] == 'short') {
					$direction = -1;
					echo "$instrument ($interval): Found short signal... " ;//. print_r($terry_result, 1);
					$overbought = 1;
				} else {
					echo '..no action' . "\n";
				}

				if ($direction != 0) {
					$bounds_strategy_name = $results_filename."_".$terry_result['bounds_method'];
					if (in_array($bounds_strategy_name, $strategy_open_position)) {
						continue;
					}

					$candle_strengths = CandleMap::get_candle_strengths($candles);

					if ($overbought) {
						$long = FALSE;
						$candle_strength = $candle_strengths['short'];
					} else if ($underbought) {
						$long = TRUE;
						$candle_strength = $candle_strengths['long'];
					}

					$endmin = $min + (2 * 60 * 60);
					list($stop, $take) = $this->get_bounds($terry_result['bounds_method'], $data, $long, $current_price, $leverage);

					if ($long) {
						if (!isset($win_or_lose_long[$terry_result['bounds_method']])) {
							$win_or_lose_long[$terry_result['bounds_method']] = $this->getWinOrLoose($instrument, $min, $endmin, TRUE, $take, $stop, $current_price, $leverage, $spread);
						}
						$result = $win_or_lose_long[$terry_result['bounds_method']];
					} else {
						if (!isset($win_or_lose_short[$terry_result['bounds_method']])) {
							$win_or_lose_short[$terry_result['bounds_method']] = $this->getWinOrLoose($instrument, $min, $endmin, FALSE, $take, $stop, $current_price, $leverage, $spread);
						}
						$result = $win_or_lose_short[$terry_result['bounds_method']];
					}

					if($result['win']) {
						echo "\nWIN!!!!!!!!!!!!!!!!!!!!\n";
					} else {
						echo "\nLOSE @@@@@@@@@@@@@@@@@@@@ \n";
					}
					print_r($result);

					// keep note of end time for this trade.
					$strategy_open_position[$bounds_strategy_name] = $result['time'];

					foreach ([$bounds_strategy_name] as $bounds_strategy_name) {
						if (1) {//for ($current_candle_count = $candle_count/*1*/; $current_candle_count <= $candle_count; $current_candle_count++) { 
							$bounds_strategy_name_with_candle_strength = $bounds_strategy_name . ' + candlestrength:' . $candle_strength;
							if (!isset($results[$bounds_strategy_name_with_candle_strength])) {
								$results[$bounds_strategy_name_with_candle_strength] = [
									'strategy_name' => $bounds_strategy_name,
									'bounds_strategy_name' => $terry_result['bounds_method'],
									'indicator_count' => $terry_result['knowledge_row']->indicator_count,
									'long_wins' => 0,
									'short_wins' => 0,
									'long_loses' => 0,
									'short_loses' => 0,
									'timeout_loses' => 0,
									'wins_plus_loses' => 0,
									'positions_count' => 0,
									'total_longs' => 0,
									'total_shorts' => 0,
									'total_wins' => 0,
									'total_loses' => 0,
									'avg_stop_take_range' => 0,
									'avg_long_profit' => 0,
									'avg_short_profit' => 0,
									'avg_profit' => 0,
									'candle_strength' => 1/* $candle_strength */,
									'interval' => $interval,
										/* these will be created as necessary
										  'long_correct_candle' => [],
										  'long_correct_inverse_candle' => [],
										  'long_wrong_candle' => [],
										  'long_wrong_inverse_candle' => [],
										  'short_correct_candle' => [],
										  'short_correct_inverse_candle' => [],
										  'short_wrong_candle' => [],
										  'short_wrong_inverse_candle' => [],

										  'long_correct_trend' => [],
										  'long_correct_inverse_trend' => [],
										  'long_wrong_trend' => [],
										  'long_wrong_inverse_trend' => [],
										  'short_correct_trend' => [],
										  'short_correct_inverse_trend' => [],
										  'short_wrong_trend' => [],
										  'short_wrong_inverse_trend' => [],
										  '% WIN' => 0, */
								];
							}

							$prefix = $long ? 'long_' : 'short_';
							$correct = $result['win'] ? 'correct_' : 'wrong_';

							foreach ([/* 'trend' => $trends[$instrument], */'candle' => isset($candles['current']) ? $candles['current'] : []] as $cot => $cots) {
								foreach ($cots as $name => $value) {
									$inverse = (($value > 0 && $long) || ($value < 0 && !$long)) ? '' : 'inverse_';
									if (!isset($results[$bounds_strategy_name_with_candle_strength][$prefix . $correct . $cot][$name])) {
										$results[$bounds_strategy_name_with_candle_strength][$prefix . $correct . $cot][$name] = 1;
									} else {
										$results[$bounds_strategy_name_with_candle_strength][$prefix . $correct . $cot][$name];
									}
								}
							}

							$results[$bounds_strategy_name_with_candle_strength]['positions_count'] ++;

							$result['win'] ? $results[$bounds_strategy_name_with_candle_strength][($long ? 'long_' : 'short_') . 'wins'] ++ : $results[$bounds_strategy_name_with_candle_strength][($long ? 'long_' : 'short_') . 'loses'] ++;
							$result['win'] ? $results[$bounds_strategy_name_with_candle_strength]['total_wins'] ++ : $results[$bounds_strategy_name_with_candle_strength]['total_loses'] ++;
							$long ? $results[$bounds_strategy_name_with_candle_strength]['total_longs'] ++ : $results[$bounds_strategy_name_with_candle_strength]['total_shorts'] ++;
							if ($result['time'] == $endmin) {
								$results[$bounds_strategy_name_with_candle_strength]['timeout_loses'] ++;
							}
							$results[$bounds_strategy_name_with_candle_strength]['wins_plus_loses'] += $result['win'] ? 1 : -1;


							$results[$bounds_strategy_name_with_candle_strength]['avg_stop_take_range'] = (($results[$bounds_strategy_name_with_candle_strength]['avg_stop_take_range'] * ($results[$bounds_strategy_name_with_candle_strength]['positions_count'] - 1)) + abs($take - $stop)) / $results[$bounds_strategy_name_with_candle_strength]['positions_count'];

							// enter with whole spread as offset (rather than worry ourselves with separate ask + bid)
							$percentage_profit = $result['percentage_profit'];

							if ($long) {
								$results[$bounds_strategy_name_with_candle_strength]['avg_long_profit'] = (($results[$bounds_strategy_name_with_candle_strength]['avg_long_profit'] * ($results[$bounds_strategy_name_with_candle_strength]['total_longs'] - 1)) + $percentage_profit) / $results[$bounds_strategy_name_with_candle_strength]['total_longs'];
							} else {
								$results[$bounds_strategy_name_with_candle_strength]['avg_short_profit'] = (($results[$bounds_strategy_name_with_candle_strength]['avg_short_profit'] * ($results[$bounds_strategy_name_with_candle_strength]['total_shorts'] - 1)) + $percentage_profit) / $results[$bounds_strategy_name_with_candle_strength]['total_shorts'];
							}
							$results[$bounds_strategy_name_with_candle_strength]['avg_profit'] = (($results[$bounds_strategy_name_with_candle_strength]['avg_profit'] * ($results[$bounds_strategy_name_with_candle_strength]['positions_count'] - 1)) + $percentage_profit) / $results[$bounds_strategy_name_with_candle_strength]['positions_count'];
						}
					}
				}
			}

			// compile result report.. print, put in knowledge table, etc
			if (!($min % 86400) || $min == $end_min) {
				$percs = [];
				foreach ($results as $strategy_name => $data) {
//					// get candle + trend % successes and fails, including their use as anti signals
					foreach (['candle', 'inverse_candle', 'trend', 'inverse_trend'] as $cot) {
						foreach (['long', 'short'] as $los) {
							$key_correct = $los . '_correct_' . $cot;
							$key_wrong = $los . '_wrong_' . $cot;
							$key_perc = 'PERCENTAGE  ' . $los . '_' . $cot;
							$all_names = [];
							if (isset($data[$key_correct])) {
								foreach ($data[$key_correct] as $name => $count) {
									$all_names[] = $name;
								}
							}
							if (isset($data[$key_wrong])) {
								foreach ($data[$key_wrong] as $name => $count) {
									$all_names[] = $name;
								}
							}
							$all_names_with_outcome = [];
							$to_sort_by = [];
							foreach (array_unique($all_names) as $name) {
								$percent = (((int) @$data[$key_correct][$name] / ((int) @$data[$key_correct][$name] + (int) @$data[$key_wrong][$name])) * 100);
								$to_sort_by[] = $percent;
								unset($results[$strategy_name][$key_perc][$name]);
								$results[$strategy_name][$key_perc][$name] = $percent;
							}
							if (count($to_sort_by)) {
								array_multisort($to_sort_by, $results[$strategy_name][$key_perc]);
							}
						}
					}

					if ($results[$strategy_name]['total_longs']) {
						unset($results[$strategy_name]['% PERCENTAGE LONG win']);
						$results[$strategy_name]['% PERCENTAGE LONG win'] = ((($results[$strategy_name]['long_wins']) / $results[$strategy_name]['total_longs']) * 100);
					}
					if ($results[$strategy_name]['total_shorts']) {
						unset($results[$strategy_name]['% PERCENTAGE SHORT win']);
						$results[$strategy_name]['% PERCENTAGE SHORT win'] = ((($results[$strategy_name]['short_wins']) / $results[$strategy_name]['total_shorts']) * 100);
					}

					$perc = 0;
					if ($results[$strategy_name]['positions_count']) {
						unset($results[$strategy_name]['% PERCENTAGE WIN']);
						$perc = $results[$strategy_name]['% PERCENTAGE WIN'] = ((($results[$strategy_name]['total_wins']) / $results[$strategy_name]['positions_count']) * 100);
					}

					$percs[] = $perc;
				}
				array_multisort($percs, $results);
				$significant_results = [];
				foreach ($results as $key => $result) {
					if ($results[$strategy_name]['% PERCENTAGE WIN'] > -1) { //50) {
						$significant_results[$key] = $result;
					}
				}
				if (empty($significant_results)) {
					$significant_results = $results;
				}
				file_put_contents("$results_dir/$results_filename", "\n\n\nprinted:" . print_r($significant_results, 1) . ' ' . print_r('min so far ' . date('Y-m-d H:i:s', $min), 1) . "\n\n $instrument: Skipped $skipped due to incomplete data");

				echo "\n\n $instrument: Skipped $skipped due to incomplete data";
				echo "\n\n $instrument: Inserting ";
			}
		}
	}

}
