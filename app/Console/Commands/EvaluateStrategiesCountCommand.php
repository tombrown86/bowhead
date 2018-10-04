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

class EvaluateStrategiesCountCommand extends Command {

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
	protected $name = 'bowhead:eval_strategies_count';
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

			$underbought = $overbought = 0;

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


			$instruments = ['EUR/USD'];

			// candles
			$candles = $cand->allCandles('EUR/USD', $data);

			// signals
			$signals = $this->signals(1, 0, ['EUR/USD'], $data);

			// trends

			foreach ($instruments as $instrument) {
				$trends[$instrument]['httc'] = $ind->httc($instrument, $data);   # Hilbert Transform - Trend vs Cycle Mode
				$trends[$instrument]['htl'] = $ind->htl($instrument, $data); # Hilbert Transform - Instantaneous Trendline
				$trends[$instrument]['hts'] = $ind->hts($instrument, $data, true); # Hilbert Transform - Sinewave
				$trends[$instrument]['mmi'] = $ind->mmi($instrument, $data); # market meanness
			}

			// our indicators
			$indicators = $ind->allSignals('EUR/USD', $data);
			unset($indicators['ma']); // not needed here.
//				foreach($trends[$instrument] as $trend_name=>$trend_value) { if($trend_value != 0) {


			$win_or_lose_long = NULL;
			$win_or_lose_short = NULL;

			$count_stats = [];

			foreach (['indicators' => $indicators, 'trends' => $trends['EUR/USD'], 'candles' => isset($candles['current']) ? $candles['current'] : []] as $typename => $res) {
				$cl = $cs = 0;
				foreach ($res as $n => $v) {
					if ($v === TRUE || $v > 0) {
						$cl ++;
					}
					if ($v === TRUE || $v < 0) {
						$cs ++;
					}
				}
				foreach (['long', 'short'] as $los) {
					for ($i = 1; $i <= 12; $i++) {
						if ($cl >= $i) {
							$count_strats['count_' . $los . '_' . $i . '_' . $typename] = $los == 'long' ? 1 : -1;
						}
					}
				}
			}


//			foreach ($indicators as $indicator_name => $indicator_value) {
//				foreach ($signals['EUR/USD'] as $signal_name => $signal_value) {
//					if (isset($candles['current'])) {
//						foreach ($candles['current'] as $candle_name => $candle_value) {
			foreach ($count_strats as $strategy_name => $value) {
//							if (isset($signal_name) && isset($indicator_name) && $signal_name == $indicator_name) {
//								continue;
//							}
//							$strategy_name = "$indicator_name" . "_$signal_name"; //. "_$candle_name";
//								$strategy_name = "$trend_name";

				if (in_array($strategy_name, $strategy_open_position)) {
					continue;
				}


				$overbought = $underbought = 0;
				if ($value > 0) {
					$overbought = 1;
				}
				if ($value < 0) {
					$underbought = 1;
				}


				/* 							if ($candle_value > 0 &&
				  $signal_value > 0 && $indicator_value > 0) {
				  //								echo $console->colorize("CREATING A LONG ORDER: $strategy_name\n", 'green');
				  $underbought = 1;
				  }
				  if ($candle_value < 0 &&
				  $signal_value < 0 && $indicator_value < 0) {
				  //								echo $console->colorize("CREATING A SHORT ORDER: $strategy_name\n", 'red');
				  $overbought = 1;
				  }
				 */

				if ($overbought || $underbought) {
					if ($overbought) {
						$long = false;
					} else if ($underbought) {
						$long = true;
					}
					$endmin = $min + (2 * 60 * 60);

					$fibs = $this->calc_fib($data); // defaults to 'EUR/USD';
					if ($long) {
						$take = $fibs['R2'];
						$stop = $fibs['S2'];
					} else {
						$take = $fibs['S2'];
						$stop = $fibs['R2'];
					}
//									$fibs = $this->calc_demark(); // defaults to 'EUR/USD';
//									print_r($fibs);die;
//									if($long) {
//										$take = $current_price + (($current_price / 100)*1);
//										$stop   = $current_price - (($current_price / 100)*1);
//									} else {
//										$take = $current_price + (($current_price / 100)*1);
//										$stop   = $current_price - (($current_price / 100)*1);
//									}

					if ($long) {
						if (!$win_or_lose_long) {
							$win_or_lose_long = $this->getWinOrLose('EUR/USD', $min, $endmin, TRUE, $take, $stop);
						}
						$result = $win_or_lose_long;
					} else {
						if (!$win_or_lose_short) {
							$win_or_lose_short = $this->getWinOrLose('EUR/USD', $min, $endmin, FALSE, $take, $stop);
						}
						$result = $win_or_lose_short;
					}

					// keep note of end time for this trade.
					$strategy_open_position[$strategy_name] = $result['time'];

					if (!isset($results[$strategy_name])) {
						$results[$strategy_name] = [
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
							'long_correct_trend' => [],
							'long_wrong_trend' => [],
//                                                                                        'long_correct_candle' => [],
//                                                                                        'long_wrong_candle' => [],
							'short_correct_trend' => [],
							'short_wrong_trend' => [],
//                                                                                        'short_correct_candle' => [],
//                                                                                        'short_wrong_candle' => [],
							'% win' => 0,
						];
					}

					$prefix = $long ? 'long_' : 'short';
//										if(isset($candles['current'])) {
//											foreach ($candles['current'] as $candle_name => $candle_value) {
//												if(($candle_value > 0 && $result['win']) || ($candle_value < 0 && !$result['win'])) {
//
//													if(!isset($results[$strategy_name][$prefix.'correct_candle'][$candle_name])) {
//														$results[$strategy_name][$prefix.'correct_candle'][$candle_name] = 1;
//													} else {
//														 $results[$strategy_name][$prefix.'correct_candle'][$candle_name];
//													}
//												}
//												if(($candle_value > 0 && !$result['win']) || ($candle_value < 0 && $result['win'])) {
//														if(!isset($results[$strategy_name][$prefix.'wrong_candle'][$candle_name])) {
//																$results[$strategy_name][$prefix.'wrong_candle'][$candle_name] = 1;
//														} else {   
//																 $results[$strategy_name][$prefix.'wrong_candle'][$candle_name]++;
//														}
//												}
//											}										
//										}
					foreach ($trends[$instrument] as $trend_name => $trend_value) {
						if ($trend_value != 0) {
							if (($trend_value > 0 && $result['win']) || ($trend_value < 0 && !$result['win'])) {
								if (!isset($results[$strategy_name][$prefix . 'correct_trend'][$trend_name])) {
									$results[$strategy_name][$prefix . 'correct_trend'][$trend_name] = 1;
								} else {
									$results[$strategy_name][$prefix . 'correct_trend'][$trend_name];
								}
							}
							if (($trend_value > 0 && !$result['win']) || ($trend_value < 0 && $result['win'])) {
								if (!isset($results[$strategy_name][$prefix . 'wrong_trend'][$trend_name])) {
									$results[$strategy_name][$prefix . 'wrong_trend'][$trend_name] = 1;
								} else {
									$results[$strategy_name][$prefix . 'wrong_trend'][$trend_name] ++;
								}
							}
						}
					}

					$results[$strategy_name]['positions_count'] ++;

					$result['win'] ? $results[$strategy_name][($long ? 'long_' : 'short_') . 'wins'] ++ : $results[$strategy_name][($long ? 'long_' : 'short_') . 'loses'] ++;
					$result['win'] ? $results[$strategy_name]['total_wins'] ++ : $results[$strategy_name]['total_loses'] ++;
					$long ? $results[$strategy_name]['total_longs'] ++ : $results[$strategy_name]['total_shorts'] ++;
					if ($result['time'] == $endmin) {
						$results[$strategy_name]['timeout_loses'] ++;
					}
					$results[$strategy_name]['wins_plus_loses'] += $result['win'] ? 1 : -1;

//								}
//							}
				}
//						}
//					}
//				}}
//				}
//			}
			}
			if (!($min % 86400) || $min == $end_min) {
				$percs = [];
				foreach ($results as $strategy_name => $data) {
					if ($results[$strategy_name]['positions_count']) {
						$results[$strategy_name]['% win'] = ((($results[$strategy_name]['total_wins']) / $results[$strategy_name]['positions_count']) * 100);
					}
					if ($results[$strategy_name]['total_longs']) {
						$results[$strategy_name]['% LONG win'] = ((($results[$strategy_name]['long_wins']) / $results[$strategy_name]['total_longs']) * 100);
					}
					if ($results[$strategy_name]['total_shorts']) {
						$results[$strategy_name]['% SHORT win'] = ((($results[$strategy_name]['short_wins']) / $results[$strategy_name]['total_shorts']) * 100);
					}
					$percs[] = $results[$strategy_name]['% win'];
				}
				array_multisort($percs, $results);

				print_r($results);
				file_put_contents('/home/terry/results/eurusd_fib_r2s2__count_multiple_indicators_or_candles_or_trends', json_encode($results) . "\n\n\nprinted:" . print_r($results, 1) . ' ' . print_r('min so far ' . date('Y-m-d H:i:s', $min), 1) . "\n\n $instrument: Skipped $skipped due to incomplete data");

				echo "\n\n $instrument: Skipped $skipped due to incomplete data";
			}
		}

//			$all_results[$take] = $results;
//		}
//		file_put_contents('/home/terry/results/nocandle_retestindstratandcandles_allresults', print_r($all_results, 1));
	}

}
