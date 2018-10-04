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
use AndreasGlaser\PPC\PPC; // https://github.com/andreas-glaser/poloniex-php-clie


class RecordSuccessCommand extends Command {

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
	protected $name = 'bowhead:record_success';
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

		$instrument = 'BTC/USD';
		$interval = '1m';

		$strategy_open_position = [];

#		for ($take = 150; $take <= 150; $take+=25) {
			$skipped = 0;
			$take = 150;
			$results = [];
			$end_min = strtotime('2018-01-19 22:00:00');
			for ($min = strtotime('2017-01-01 05:00:00'); $min <= $end_min; $min += 60) {
				$underbought = $overbought = 0;

				$data = $this->getRecentData($instrument, 200, false, date('H'), $interval, false, $min, false);
                                       if(max($data['periods']) > 500) {
                                               $skipped ++ ;
                                               echo "\n !!!!!!!!!!!!!!! Skipping, max period was ".max($data['periods']) . "! \n";
                                               continue;
                                       }

				$current_price = ($data['high'][count($data['low']) - 1] + $data['low'][count($data['low']) - 1]) / 2;

				$list_indicators = array('adx', 'aroonosc', 'cmo', 'sar', 'cci', 'mfi', 'obv', 'stoch', 'rsi', 'macd', 'bollingerBands', 'atr', 'er', 'hli', 'ultosc', 'willr', 'roc', 'stochrsi');
				$list_signals = ['rsi', 'stoch', 'stochrsi', 'macd', 'adx', 'willr', 'cci', 'atr', 'hli', 'ultosc', 'roc', 'er'];


				$instruments = ['BTC/USD'];

				// candles
				$candles = $cand->allCandles('BTC/USD', $data);

				// signals
				$signals = $this->signals(1, 0, ['BTC/USD'], $data);

				// trends

				foreach ($instruments as $instrument) {
					$trends[$instrument]['httc'] = $ind->httc($instrument, $data);   # Hilbert Transform - Trend vs Cycle Mode
					$trends[$instrument]['htl'] = $ind->htl($instrument, $data);	# Hilbert Transform - Instantaneous Trendline
					$trends[$instrument]['hts'] = $ind->hts($instrument, $data, true); # Hilbert Transform - Sinewave
					$trends[$instrument]['mmi'] = $ind->mmi($instrument, $data);	# market meanness
				}


				$row = [];
                                
				$result_long_100th = $this->getWinOrLose('BTC/USD', $min, $endmin, TRUE, $current_price + (($current_price / 100)*1) , $current_price - (($current_price / 100)*1));
				$result_short_100th = $this->getWinOrLose('BTC/USD', $min, $endmin, FALSE, $current_price - (($current_price / 100)*1), $current_price + (($current_price / 100)*1)); 

				$result_long_80th = $this->getWinOrLose('BTC/USD', $min, $endmin, TRUE, $current_price + (($current_price / 80)*1), $current_price - (($current_price / 80)*1));
				$result_short_80th = $this->getWinOrLose('BTC/USD', $min, $endmin, FALSE, $current_price - (($current_price / 80)*1), $current_price + (($current_price / 80)*1)); 

				$result_long_60th = $this->getWinOrLose('BTC/USD', $min, $endmin, TRUE, $current_price + (($current_price / 60)*1), $current_price - (($current_price / 60)*1));
				$result_short_60th = $this->getWinOrLose('BTC/USD', $min, $endmin, FALSE, $current_price - (($current_price / 60)*1), $current_price + (($current_price / 60)*1));


				$row = [
						'long_100th_win' => $result_long_100th['win'],'short_100th_win' => $result_short_100th['win'],
						'long_80th_win' => $result_long_80th['win'],'short_80th_win' => $result_short_80th['win'],
						'long_60th_win' => $result_long_60th['win'],'short_60th_win' => $result_short_60th['win'],
					];

				// our indicators
				$indicators = $ind->allSignals('BTC/USD', $data);
				unset($indicators['ma']); // not needed here.

//				foreach($trends[$instrument] as $trend_name=>$trend_value) { if($trend_value != 0) {
				foreach ($indicators as $indicator_name => $indicator_value) {
					foreach ($signals['BTC/USD'] as $signal_name => $signal_value) {
//						if (isset($candles['current'])) {
//							foreach ($candles['current'] as $candle_name => $candle_value) {
								if (isset($signal_name) && isset($indicator_name) && $signal_name == $indicator_name) {
									continue;
								}
								$strategy_name = "$indicator_name" . "_$signal_name"; //. "_$candle_name";
//								$strategy_name = "$trend_name";

								if (in_array($strategy_name, $strategy_open_position)) {
									continue;
								}


								$overbought = $underbought = 0;
							//	if($trend_value > 0) {$overbought=1;}
							//	if($trend_value < 0) {$underbought=1;}


								if (//$candle_value > 0 &&
										$signal_value > 0 && ($indicator_value === TRUE || $indicator_value > 0)) {
									//								echo $console->colorize("CREATING A LONG ORDER: $strategy_name\n", 'green');
									$underbought = 1;
								}
								if (//$candle_value < 0 &&
										$signal_value < 0 && ($indicator_value === TRUE || $indicator_value < 0)) {
									//								echo $console->colorize("CREATING A SHORT ORDER: $strategy_name\n", 'red');
									$overbought = 1;
								}


								if ($overbought || $underbought) {
									if ($overbought) {
										$long = false;
									} else if ($underbought) {
										$long = true;
									}
									$endmin = $min + (2 * 60 * 60);

//									$fibs = $this->calc_fib([]); // defaults to 'BTC/USD';
//									$fibs = $this->calc_demark(); // defaults to 'BTC/USD';
//									print_r($fibs);die;
									if($long) {
										$take = $current_price + (($current_price / 100)*1);
										$stop   = $current_price - (($current_price / 100)*1);
									} else {
										$take = $current_price + (($current_price / 100)*1);
										$stop   = $current_price - (($current_price / 100)*1);
									}
									
									$result = $this->getWinOrLose('BTC/USD', $min, $endmin, $long, $take, $stop);

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
                                                                                        'long_correct_candle' => [],
                                                                                        'long_wrong_candle' => [],


                                                                                        'short_correct_trend' => [],
                                                                                        'short_wrong_trend' => [],
                                                                                        'short_correct_candle' => [],
                                                                                        'short_wrong_candle' => [],




											'% win' => 0,
										];
									}

										$prefix = $long ? 'long_' : 'short';
										if(isset($candles['current'])) {
											foreach ($candles['current'] as $candle_name => $candle_value) {
												if(($candle_value > 0 && $result['win']) || ($candle_value < 0 && !$result['win'])) {

													if(!isset($results[$strategy_name][$prefix.'correct_candle'][$candle_name])) {
														$results[$strategy_name][$prefix.'correct_candle'][$candle_name] = 1;
													} else {
														 $results[$strategy_name][$prefix.'correct_candle'][$candle_name];
													}
												}
                                                                                                if(($candle_value > 0 && !$result['win']) || ($candle_value < 0 && $result['win'])) {
                                                                                                        if(!isset($results[$strategy_name][$prefix.'wrong_candle'][$candle_name])) {
                                                                                                                $results[$strategy_name][$prefix.'wrong_candle'][$candle_name] = 1;
                                                                                                        } else {   
                                                                                                                 $results[$strategy_name][$prefix.'wrong_candle'][$candle_name]++;
                                                                                                        }
                                                                                                }
											}										
										}
										foreach($trends[$instrument] as $trend_name=>$trend_value) {
											if($trend_value != 0) {
                                                                                                if(($trend_value > 0 && $result['win']) || ($trend_value < 0 && !$result['win'])) {
                                                                                                        if(!isset($results[$strategy_name][$prefix.'correct_trend'][$trend_name])) {
                                                                                                                $results[$strategy_name][$prefix.'correct_trend'][$trend_name] = 1;
                                                                                                        } else {
                                                                                                                 $results[$strategy_name][$prefix.'correct_trend'][$trend_name];
                                                                                                        }
                                                                                                }
                                                                                                if(($trend_value > 0 && !$result['win']) || ($trend_value < 0 && $result['win'])) {
                                                                                                        if(!isset($results[$strategy_name][$prefix.'wrong_trend'][$trend_name])) {
                                                                                                                $results[$strategy_name][$prefix.'wrong_trend'][$trend_name] = 1;
                                                                                                        } else {   
                                                                                                                 $results[$strategy_name][$prefix.'wrong_trend'][$trend_name]++;
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
//									$min = $result['time'];


//								}
//							}
						}
					}
				}
//				}}

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
					file_put_contents('/home/terry/results/count_candle_and_trend_successes_against_sig+indc_results_so_far', json_encode($results)."\n\n\nprinted:".print_r($results, 1) . ' ' . print_r('min so far ' . date('Y-m-d H:i:s', $min), 1) .  "\n\n $instrument: Skipped $skipped due to incomplete data");

                                        echo "\n\n $instrument: Skipped $skipped due to incomplete data";

				}
			}

//			$all_results[$take] = $results;
//		}
//		file_put_contents('/home/terry/results/nocandle_retestindstratandcandles_allresults', print_r($all_results, 1));
	}

}
