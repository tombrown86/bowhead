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

class EvaluateStrategies2Command extends Command {

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
	protected $name = 'bowhead:eval_strategies2';
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

		$interval = '5m';
		
		$strategies = $this->{"strategies_".$interval};

		$instruments = ['BTC/USD'];
		$instruments = ['EUR/USD'];
		$leverages   = [222,200,100,88,50,25,1];

		$strategy_open_position = [];

              
		foreach ($instruments as $instrument) {
			$skipped = 0;
			for ($take = 150; $take <= 150; $take+=25) {
				$take = 150;
				$results = [];
				$end_min = strtotime('2018-01-19 22:00:00');
				$end_min = strtotime('2017-01-15 22:00:00');
				for ($min = strtotime('2017-01-05 06:00:00'); $min <= $end_min; $min += 60*5) {
					foreach ($strategy_open_position as $strategy => $etime) {
						if ($etime < $min) {
							unset($strategy_open_position[$strategy]);
						}
					}


					$data = $this->getRecentData($instrument, 200, false, date('H'), $interval, false, $min, false); 
	                                if(max($data['periods']) > 500) {
                                	        $skipped ++ ;
                        	                print_r($data);
                	                        echo "\nSkipping, max period was ".max($data['periods']) . "! \n";
        	                                continue;
	                                }

					$current_price = ($data['high'][count($data['low']) - 1] + $data['low'][count($data['low']) - 1]) / 2;


					//				// candles
					//				$candles = $cand->allCandles('BTC/USD', $data);
					// signals
					$signals = $this->signals(1, 0, [$instrument], $data);


					foreach ($strategies as $strategy) {
						$function_name = $strategy;
						$flags[$strategy] = $this->$function_name($instrument, $data);
					}
					$pair_strategies[$instrument] = $flags;

					//				// trends
					//				foreach ($instruments as $instrument) {
					//					$trends[$instrument]['httc'] = $ind->httc($instrument, $data);	  # Hilbert Transform - Trend vs Cycle Mode
					//					$trends[$instrument]['htl'] = $ind->htl($instrument, $data);	   # Hilbert Transform - Instantaneous Trendline
					//					$trends[$instrument]['hts'] = $ind->hts($instrument, $data, true); # Hilbert Transform - Sinewave
					//					$trends[$instrument]['mmi'] = $ind->mmi($instrument, $data);	   # market meanness
					//				}
					// our indicators
					//				$indicators = $ind->allSignals('BTC/USD', $data);
					//				unset($indicators['ma']); // not needed here.

					foreach ($pair_strategies as $pair => $strats) {
						$sigs = $this->compileSignals($signals[$pair], 1);
						foreach ($strats as $strategy => $flag) {
							if ($flag == 0) {
								continue; // not a short or a long
							}
							$direction = ($pair_strategies[$pair][$strategy] > 0 ? 'long' : 'short');

//							$lev = 'no particular lev';
							/**
							 *  Here we determine the leverage based on signals.
							 *  There are only a certain leverage steps we can use
							 *  so we need to fit into the closest 222,200,100,88,50,25,1
							 */
							$lev = 220;
							$closest = 0;
							$lev = ($direction == 'long' ? $lev - ($sigs['neg'] * 20) : $lev - ($sigs['pos'] * 20));
							foreach ($leverages as $leverage) {
								if (abs($lev - $closest) > abs($leverage - $lev)) {
									$closest = $leverage;
								}
							}
							$lev = $closest;
							if ($lev < 25) {
								$lev = 25;
							}

//							if($lev >=150) {continue;} // too risky?
							
							echo "\nCreate $direction ($lev) for $pair $strategy";
							//						$order = $this->createPosition($pair, $direction, $strategy, $sigs['pos'], $sigs['neg'], 2, $lev);


							$strategy_name = $pair . '_' . $strategy;

							if (in_array($strategy_name, $strategy_open_position)) {
								continue;
							}


							$long = $direction == 'long';
							$endmin = $min + (60 * 60);

							$price = $current_price;
							$tp = round(( $price * (20/$lev) ) / 100, 5);
							$sl = round(( $price * (10/$lev) ) / 100, 5);
							$amt_takeprofit = ($direction == 'long' ? ((float)$price + $tp) : ((float)$price - $tp));
							$amt_stoploss   = ($direction == 'long' ? ((float)$price - $sl) : ((float)$price + $sl));

							
							$result = $this->getWinOrLose($instrument, $min, $endmin, $long, $amt_takeprofit, $amt_stoploss);
							

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
									'% win' => 0,
								];
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
						}
					}
					if (!($min % 86400) || $min == $end_min) {
						echo ('poo');
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
						file_put_contents('/tmp/strats_results_so_far', print_r($results, 1) . ' ' . print_r('min so far ' . date('Y-m-d H:i:s', $min), 1));

	                                        echo "\n\n $instrument: Skipped $skipped due to incomplete data";

					}
				}

//				$all_results[$take] = $results;
			}
		}
//		file_put_contents('/tmp/strats_allresults', print_r($all_results, 1));
	}

	/**
	 * @param      $arr
	 * @param bool $retarr
	 *
	 * @return array|string
	 */
	public function compileSignals($arr, $retarr = false) {
		$console = new Util\Console();
		$pos = $neg = 0;
		foreach ($arr as $a) {
			$pos += ($a > 0 ? 1 : 0);
			$neg += ($a < 0 ? 1 : 0);
		}
		#$pos = $console->colorize($pos,'green');
		#$neg = $console->colorize($neg,'red');

		if ($retarr) {
			return ['pos' => $pos, 'neg' => $neg];
		}
		return "$pos/-$neg";
	}

}
