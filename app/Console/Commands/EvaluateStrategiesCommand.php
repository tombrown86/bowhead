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

ini_set('memory_limit','1G'); 

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


#		for ($take = 150; $take <= 150; $take+=25) {
		$skipped = 0;
		$take = 150;
		$results = [];
		$end_min = strtotime('2018-12-29 22:00:00');
		for ($min = strtotime('2017-01-01 05:00:00'); $min <= $end_min; $min += 60) {
			foreach ($strategy_open_position as $strategy => $etime) {
				if ($etime < $min) {
					unset($strategy_open_position[$strategy]);
				}
			}
			foreach (['1m', '5m', '15m', /* '30m', '1h' */] as $interval) {
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

				$list_indicators = array('adx', 'aroonosc', 'cmo', 'sar', 'cci', 'mfi', 'obv', 'stoch', 'rsi', 'macd', 'bollingerBands', 'atr', 'er', 'hli', 'ultosc', 'willr', 'roc', 'stochrsi');
				$list_signals = ['rsi', 'stoch', 'stochrsi', 'macd', 'adx', 'willr', 'cci', 'atr', 'hli', 'ultosc', 'roc', 'er'];

				
				$doubles = [];
				$this->combinations($list_indicators, 2, $doubles);
				$doubles = $this->unique_combinations($doubles);
				$triplets = [];
				$this->combinations($list_indicators, 3, $triplets);
				$triplets = $this->unique_combinations($triplets);
				$indicator_combinations = array_merge($doubles, $triplets);
				
				
				$instruments = ['EUR/USD'];

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
					$strategy_name = '';
					foreach ($indicators as $indicator_name) {
						$strategy_name .= '_'.$indicator_name;
					}

					if (in_array($strategy_name, $strategy_open_position)) {
						continue;
					}


					$all_overbought = $all_underbought = TRUE;

					foreach($indicators as $indicator) {
						if(!isset($indicator_results[$indicator])) {
							continue;
						}

						$all_overbought = $all_overbought && $indicator_results[$indicator] < 1;
						$all_underbought = $all_underbought && $indicator_results[$indicator] > 1;
					}

					if(!$all_overbought && !$all_underbought) {
						continue;
					}

					
					if (isset($candles['current'])) { // check for at least 1 candle
						foreach ($candles['current'] as $candle_name => $candle_value) {
							if ($candle_value > 0 && $all_underbought) {
								$underbought = 1;
							}
							if ($candle_value < 0 && $all_overbought) {
								$overbought = 1;
							}

							if ($overbought || $underbought) {
								foreach ([/*'demark', 'fib_r2s2', 'fib_r3s3', */'perc_20_20', /*'perc_10_20'*/] as $bounds_method) {
									$bounds_strategy_name = "$bounds_method $strategy_name";

									if ($overbought) {
										$long = false;
									} else if ($underbought) {
										$long = true;
									}
									$endmin = $min + (2 * 60 * 60);
									$leverage = '222';
									list($stop, $take) = $this->get_bounds($bounds_method, $data, $long, $current_price, $leverage);

									if ($long) {
//										if (!$win_or_lose_long) {
											$win_or_lose_long = $this->getWinOrLoose('EUR/USD', $min, $endmin, TRUE, $take, $stop);
//										}
										$result = $win_or_lose_long;
									} else {
//										if (!$win_or_lose_short) {
											$win_or_lose_short = $this->getWinOrLoose('EUR/USD', $min, $endmin, FALSE, $take, $stop);
//										}
										$result = $win_or_lose_short;
									}

									// keep note of end time for this trade.
									$strategy_open_position[$bounds_strategy_name] = $result['time'];

									foreach ([$bounds_strategy_name, 'all'] as $bounds_strategy_name) {
										if (!isset($results[$bounds_strategy_name])) {
											$results[$bounds_strategy_name] = [
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
												'avg_stop_take_range' => [],
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

										foreach ([/*'trend' => $trends[$instrument], */'candle' => isset($candles['current']) ? $candles['current'] : []] as $cot => $cots) {
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

//										$results[$bounds_strategy_name]['avg_stop_take_range'][] = abs($take - $stop);
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
//					foreach (['candle', 'inverse_candle', 'trend', 'inverse_trend'] as $cot) {
//						foreach (['long', 'short'] as $los) {
//							$key_correct = $los . '_correct_' . $cot;
//							$key_wrong = $los . '_wrong_' . $cot;
//							$key_perc = 'PERCENTAGE  ' . $los . '_' . $cot;
//							$all_names = [];
//							if (isset($data[$key_correct])) {
//								foreach ($data[$key_correct] as $name => $count) {
//									$all_names[] = $name;
//								}
//							}
//							if (isset($data[$key_wrong])) {
//								foreach ($data[$key_wrong] as $name => $count) {
//									$all_names[] = $name;
//								}
//							}
//							$all_names_with_outcome = [];
//							$to_sort_by = [];
//							foreach (array_unique($all_names) as $name) {
//								$percent = (((int) @$data[$key_correct][$name] / ((int) @$data[$key_correct][$name] + (int) @$data[$key_wrong][$name])) * 100);
//								$to_sort_by[] = $percent;
//								unset($results[$strategy_name][$key_perc][$name]);
//								$results[$strategy_name][$key_perc][$name] = $percent;
//							}
//							if (count($to_sort_by)) {
//								array_multisort($to_sort_by, $results[$strategy_name][$key_perc]);
//							}
//						}
//					}

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

					// get avg bound range
//					if(count($results[$strategy_name]['avg_stop_take_range'])) {
//						$results[$strategy_name]['avg_stop_take_range'] = array_sum($results[$strategy_name]['avg_stop_take_range']) / count($results[$strategy_name]['avg_stop_take_range']);
//					} else {
//						$results[$strategy_name]['avg_stop_take_range'] = 'null';
//					}


					$percs[] = $perc;
				}
				
				array_multisort($percs, $results);

				$significant_results = [];
				foreach($results as $result){
					if($results[$strategy_name]['% PERCENTAGE WIN'] > 50) {
						$significant_results[] = $result;
					}
				} 
				if(empty($significant_results)) {
					$significant_results = $results;
				}
				file_put_contents('/home/terry/results/eurusd_MANY_IND_COMBINATIONs_any_candle_1and5mand15m_Intervals', "\n\n\nprinted:" . print_r($significant_results, 1) . ' ' . print_r('min so far ' . date('Y-m-d H:i:s', $min), 1) . "\n\n $instrument: Skipped $skipped due to incomplete data");

				echo "\n\n $instrument: Skipped $skipped due to incomplete data";
			}
		}
	}

	function combinations($arr, $level, &$result, $curr = array()) {
		for ($i = 0; $i < count($arr); $i++) {
			$new = array_merge($curr, array($arr[$i]));
			if ($level == 1) {
				sort($new);
				if (!in_array($new, $result)) {
					$result[] = $new;
				}
			} else {
				$this->combinations($arr, $level - 1, $result, $new);
			}
		}
	}

	function unique_combinations($list) {
		$size = count($list[0]);
		$unique_list = [];
		foreach($list as $items) {
			if(count(array_unique($items)) == $size) {
				$unique_list[] = $items;
			}
		}
		return $unique_list;
	}
}
