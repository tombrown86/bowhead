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
ini_set('memory_limit', '1G');

class EvaluateStrategiesCommand extends Command {

	use Signals,
	 Strategies,
	 CandleMap,
	 OHLC,
	 Pivots; // add our traits

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'bowhead:eval_strategies';
	protected $description = '';
	protected $order_cooloff;

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

		$util = new Util\BrokersUtil();
		$console = new \Bowhead\Util\Console();
		$indicators = new \Bowhead\Util\Indicators();
		$cand = new Util\Candles();
		$ind = new Util\Indicators();

		$instrument = 'EUR/USD';
		$interval = '1m';

		$strategy_open_position = [];

		$list_indicators = array('adx', 'aroonosc', 'cmo', 'sar', 'cci', 'mfi', 'obv', 'stoch', 'rsi', 'macd', 'bollingerBands', 'atr', 'er', 'hli', 'ultosc', 'willr', 'roc', 'stochrsi');
		$list_signals = ['rsi', 'stoch', 'stochrsi', 'macd', 'adx', 'willr', 'cci', 'atr', 'hli', 'ultosc', 'roc', 'er'];


		$doubles = [];
		$this->combinations($list_indicators, 2, $doubles);
		$triplets = [];
		$this->combinations($list_indicators, 3, $triplets);
		$quadruples = [];
		$this->combinations($list_indicators, 4, $quadruples);
		$indicator_combinations = array_merge($doubles, $triplets, $quadruples);


#		for ($take = 150; $take <= 150; $take+=25) {
		$skipped = 0;
		$take = 150;
		$results = [];
		$end_min = strtotime('2018-01-01 05:00:00');
		$start_min = strtotime('2017-01-01 05:00:00');
		$spread = '0.01';
		$leverage = 222;

		for ($min = $start_min; $min <= $end_min; $min += 60) {
			foreach ($strategy_open_position as $strategy => $etime) {
				if ($etime < $min) {
					unset($strategy_open_position[$strategy]);
				}
			}
			foreach (['1m', '5m', '15m', /* '30m', '1h' */] as $interval) {
				$win_or_lose_short = $win_or_lose_long = []; //keep results here as we have multiple strats to check 
				$data = $this->getRecentData($instrument, 200, false, date('H'), $interval, false, $min, false);

				if (count($data['periods']) < 200) {
					$skipped ++;
					echo "\n !!!!!!!!!!!!!!! Less than 200 periods returned ($min)! \n";
					continue;
				}
				if (max($data['periods']) > 500) {
					$skipped ++;
					echo "\n !!!!!!!!!!!!!!! Skipping, max period was " . max($data['periods']) . "! \n";
					continue;
				}
				$current_price = ($data['high'][count($data['low']) - 1] + $data['low'][count($data['low']) - 1]) / 2;

				// candles
				$candles = $cand->allCandles('EUR/USD', $data);

				// signals
//				$signals = $this->signals(1, 0, ['EUR/USD'], $data);
				// trends
//				foreach ($instruments as $instrument) {
//					$trends[$instrument]['httc'] = $ind->httc($instrument, $data);   # Hilbert Transform - Trend vs Cycle Mode
//					$trends[$instrument]['htl'] = $ind->htl($instrument, $data); # Hilbert Transform - Instantaneous Trendline
//					$trends[$instrument]['hts'] = $ind->hts($instrument, $data, true); # Hilbert Transform - Sinewave
//					$trends[$instrument]['mmi'] = $ind->mmi($instrument, $data); # market meanness
//				}
				// our indicators
				$indicator_results = $ind->allSignals('EUR/USD', $data);

//				foreach($trends[$instrument] as $trend_name=>$trend_value) { if($trend_value != 0) {



				foreach ($indicator_combinations as $indicators) {
					$strategy_name = $interval;
					foreach ($indicators as $indicator_name) {
						$strategy_name .= '_' . $indicator_name;
					}

					if (in_array($strategy_name, $strategy_open_position)) {
						continue;
					}

					$indicators_overbought = $indicators_underbought = TRUE;
					foreach ($indicators as $indicator) {
						if (!isset($indicator_results[$indicator])) {
							$indicators_overbought = $indicators_underbought = FALSE;
							break;
						}

						$indicators_overbought = $indicators_overbought && $indicator_results[$indicator] < 0;
						$indicators_underbought = $indicators_underbought && $indicator_results[$indicator] > 0;
					}
					if (($indicators_overbought XOR $indicators_underbought) && isset($candles['current'])) { // check for at least 1 candle
						foreach ($candles['current'] as $candle_name => $candle_value) {
							$underbought = $overbought = 0;
							if ($candle_value > 0 && $indicators_underbought) {
								$underbought = 1;
							} else if ($candle_value < 0 && $indicators_overbought) {
								$overbought = 1;
							}

							if ($overbought XOR $underbought) {
								foreach (['demark', 'fib_r2s2', 'fib_r3s3', 'perc_20_20', 'perc_30_40', 'perc_40_40'/* 'perc_10_20' */] as $bounds_method) {
									$bounds_strategy_name = "$bounds_method $strategy_name";
									if ($overbought) {
										$long = FALSE;
									} else if ($underbought) {
										$long = TRUE;
									}

									$endmin = $min + (2 * 60 * 60);
									list($stop, $take) = $this->get_bounds($bounds_method, $data, $long, $current_price, $leverage);

									if ($long) {
										if (!isset($win_or_lose_long[$bounds_method])) {
											$win_or_lose_long[$bounds_method] = $this->getWinOrLoose('EUR/USD', $min, $endmin, TRUE, $take, $stop);
										}
										$result = $win_or_lose_long[$bounds_method];
									} else {
										if (!isset($win_or_lose_short[$bounds_method])) {
											$win_or_lose_short[$bounds_method] = $this->getWinOrLoose('EUR/USD', $min, $endmin, FALSE, $take, $stop);
										}
										$result = $win_or_lose_short[$bounds_method];
									}

									// keep note of end time for this trade.
									$strategy_open_position[$bounds_strategy_name] = $result['time'];

									foreach ([$bounds_strategy_name, 'all'] as $bounds_strategy_name) {
										if (!isset($results[$bounds_strategy_name])) {
											$results[$bounds_strategy_name] = [
												'strategy_name' => 'IC+1cand__' . implode('_', $indicators),
												'bounds_strategy_name' => $bounds_method,
												'indicator_count' => count($indicators),
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
												if (!isset($results[$bounds_strategy_name][$prefix . $correct . $cot][$name])) {
													$results[$bounds_strategy_name][$prefix . $correct . $cot][$name] = 1;
												} else {
													$results[$bounds_strategy_name][$prefix . $correct . $cot][$name];
												}
											}
										}

										$results[$bounds_strategy_name]['positions_count'] ++;

										$result['win'] ? $results[$bounds_strategy_name][($long ? 'long_' : 'short_') . 'wins'] ++ : $results[$bounds_strategy_name][($long ? 'long_' : 'short_') . 'loses'] ++;
										$result['win'] ? $results[$bounds_strategy_name]['total_wins'] ++ : $results[$bounds_strategy_name]['total_loses'] ++;
										$long ? $results[$bounds_strategy_name]['total_longs'] ++ : $results[$bounds_strategy_name]['total_shorts'] ++;
										if ($result['time'] == $endmin) {
											$results[$bounds_strategy_name]['timeout_loses'] ++;
										}
										$results[$bounds_strategy_name]['wins_plus_loses'] += $result['win'] ? 1 : -1;


										$results[$bounds_strategy_name]['avg_stop_take_range'] = (($results[$bounds_strategy_name]['avg_stop_take_range'] * ($results[$bounds_strategy_name]['positions_count'] - 1)) + abs($take - $stop)) / $results[$bounds_strategy_name]['positions_count'];


										$profit_percentage = $leverage * ((($result['win'] ? abs($take - $current_price) : -abs($stop - $current_price)) / $current_price) - $spread);

										if ($long) {
											$results[$bounds_strategy_name]['avg_long_profit'] = (($results[$bounds_strategy_name]['avg_long_profit'] * ($results[$bounds_strategy_name]['total_longs'] - 1)) + $profit_percentage) / $results[$bounds_strategy_name]['total_longs'];
										} else {
											$results[$bounds_strategy_name]['avg_short_profit'] = (($results[$bounds_strategy_name]['avg_short_profit'] * ($results[$bounds_strategy_name]['total_shorts'] - 1)) + $profit_percentage) / $results[$bounds_strategy_name]['total_shorts'];
										}
										$results[$bounds_strategy_name]['avg_profit'] = (($results[$bounds_strategy_name]['avg_profit'] * ($results[$bounds_strategy_name]['positions_count'] - 1)) + $profit_percentage) / $results[$bounds_strategy_name]['positions_count'];
									}
								}
							}
						}
					}
				}
			}

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
					if ($results[$strategy_name]['% PERCENTAGE WIN'] > 50) {
						$significant_results[$key] = $result;
					}
				}
				if (empty($significant_results)) {
					$significant_results = $results;
				}
				file_put_contents('/home/terry/results/eurusd_QUAD_MANY_IND_COMBINATIONs_and_several_BOUNDS_any_candle_1and5mand15m_Intervals', "\n\n\nprinted:" . print_r($significant_results, 1) . ' ' . print_r('min so far ' . date('Y-m-d H:i:s', $min), 1) . "\n\n $instrument: Skipped $skipped due to incomplete data");


				echo "\n\n $instrument: Skipped $skipped due to incomplete data";
				echo "\n\n $instrument: Inserting ";

				$tom_strategy_knowledge = new Models\tom_strategy_knowledge();
				$years_of_data = ($min - $start_min) / 3.154e+7;
			}
			if (!($min % 1296000/* 15 days */) || $min == $end_min) {
				foreach ($results as $result) {
					$unique_fields = [
						'strategy_name' => $result['strategy_name']
						, 'bounds_strategy_name' => $result['bounds_strategy_name']
						, 'instrument' => $instrument];
					$data = [
						'percentage_win' => (float) @$result['% PERCENTAGE WIN'] * $years_of_data
						, 'percentage_long_win' => (float) @$result['% PERCENTAGE LONG win'] * $years_of_data
						, 'percentage_short_win' => (float) @$result['% PERCENTAGE SHORT win'] * $years_of_data
						, 'avg_stop_take_range' => (float) @$result['avg_stop_take_range']
						, 'avg_long_profit' => (float) @$result['avg_long_profit']
						, 'avg_short_profit' => (float) @$result['avg_short_profit']
						, 'avg_profit' => (float) @$result['avg_profit']
						, 'long_wins_per_year' => (float) @$result['long_wins'] * $years_of_data
						, 'short_wins_per_year' => (float) @$result['short_wins'] * $years_of_data
						, 'long_loses_per_year' => (float) @$result['long_loses'] * $years_of_data
						, 'short_loses_per_year' => (float) @$result['short_loses'] * $years_of_data
						, 'timeout_loses_per_year' => (float) @$result['timeout_loses'] * $years_of_data
						, 'longs_per_year' => (float) @$result['total_longs'] * $years_of_data
						, 'shorts_per_year' => (float) @$result['total_shorts'] * $years_of_data
						, 'indicator_count' => (int) @$result['indicator_count']
					];
					$tom_strategy_knowledge::updateOrCreate($unique_fields, $data);
				}
			}
		}
	}

	function combinations($arr, $level, &$result, $curr = array()) {
		for ($i = 0; $i < count($arr); $i++) {
			$new = array_merge($curr, array($arr[$i]));
			if ($level == 1) {
				sort($new);
				if (!in_array($new, $result) // unique entries only
						&& count(array_unique($new)) == count($new)/* distinct sets only */) {
					$result[] = $new;
				}
			} else {
				$this->combinations($arr, $level - 1, $result, $new);
			}
		}
	}

}
