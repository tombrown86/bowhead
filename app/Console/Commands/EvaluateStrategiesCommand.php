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
class EvaluateStrategiesCommand extends Command
{
    use Signals, Strategies, CandleMap, OHLC, Pivots; // add our traits

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'bowhead:eval_strategies';
    protected $description = '';

    protected $order_cooloff;



    public function doColor($val)
    {
        if ($val == 0){ return 'none'; }
        if ($val == 1){ return 'green'; }
        if ($val == -1){ return 'magenta'; }
        return 'none';
    }

    /**
     * @return null
     *
     *  this is the part of the command that executes.
     */
    public function handle()
    {
        echo "PRESS 'q' TO QUIT AND CLOSE ALL POSITIONS\n\n\n";
        stream_set_blocking(STDIN, 0);

        $util        = new Util\BrokersUtil();
        $console     = new \Bowhead\Util\Console();
        $indicators  = new \Bowhead\Util\Indicators();
        $cand        = new Util\Candles();
        $ind         = new Util\Indicators();

		$instrument = 'BTC/USD';
		
		$strategy_open_position = [];

		for($take=150; $take<=150; $take+=25) {
			$take = 200;
			$results = [];
#			$end_min = strtotime('2018-01-19 23:00:00');
			$end_min = strtotime('2017-12-02 05:00:00');
			for($min = strtotime('2017-12-01 05:00:00'); $min <= $end_min; $min += 60) {
				foreach($strategy_open_position as $strategy=>$etime) {
					if($etime < $min) {
						unset($strategy_open_position[$strategy]);
					}
				}

				$underbought = $overbought = 0;

				$data = $this->getRecentData($instrument, 200, false, date('H'), '1m', false, $min);
				$current_price = ($data['high'][count($data['low'])-1] + $data['low'][count($data['low'])-1]) / 2;

				$list_indicators = array('adx','aroonosc','cmo','sar','cci','mfi','obv','stoch','rsi','macd','bollingerBands','atr','er','hli','ultosc','willr','roc','stochrsi');
				$list_signals    = ['rsi','stoch','stochrsi','macd','adx','willr','cci','atr','hli','ultosc','roc','er'];


				$instruments = ['BTC/USD'];

				// candles
				$candles = $cand->allCandles('BTC/USD', $data);

				// signals
				$signals = $this->signals(1, 0, ['BTC/USD'], $data);

				// trends
				foreach($instruments as $instrument) {
					$trends[$instrument]['httc'] = $ind->httc($instrument, $data);      # Hilbert Transform - Trend vs Cycle Mode
					$trends[$instrument]['htl']  = $ind->htl($instrument, $data);       # Hilbert Transform - Instantaneous Trendline
					$trends[$instrument]['hts']  = $ind->hts($instrument, $data, true); # Hilbert Transform - Sinewave
					$trends[$instrument]['mmi']  = $ind->mmi($instrument, $data);       # market meanness
				}

				// our indicators
				$indicators = $ind->allSignals('BTC/USD', $data);
				unset($indicators['ma']); // not needed here.

				foreach ($indicators as $indicator_name => $indicator_value) {
					foreach ($signals['BTC/USD'] as $signal_name => $signal_value) {
						if(isset($candles['current'])) {
							foreach($candles['current'] as $candle_name => $candle_value) {
								if ($signal_name == $indicator_name) {
									continue;
								}
								$strategy_name = "$indicator_name". "_$signal_name" . "_$candle_name";

								if(in_array($strategy_name,  $strategy_open_position)) {
									continue;
								}


								if ($candle_value > 0 &&
										$signal_value > 0 && $indicator_value > 0) {
	//								echo $console->colorize("CREATING A LONG ORDER: $strategy_name\n", 'green');
									$underbought = 1;
								}
								if ($candle_value < 0 && 
										$signal_value < 0 && $indicator_value < 0) {
	//								echo $console->colorize("CREATING A SHORT ORDER: $strategy_name\n", 'red');
									$overbought = 1;
								}


								if($overbought || $underbought) {
									if($overbought) {
										$long = false;
									} else if($underbought) {
										$long = true;
									}
									$endmin = $min+(60*60);

									$result = $this->getWinOrLoose('BTC/USD', $min, $endmin, $long, $current_price + ($overbought ? -$take:$take), $current_price + ($overbought?150:-150));

									// keep note of end time for this trade.
									$strategy_open_position[$strategy_name] = $result['time'];

									if(!isset($results[$strategy_name])) {
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

									$results[$strategy_name]['positions_count']++;

									$result['win'] ? $results[$strategy_name][($long ? 'long_':'short_').'wins']++ : $results[$strategy_name][($long ? 'long_':'short_').'loses']++;
									$result['win'] ? $results[$strategy_name]['total_wins']++ : $results[$strategy_name]['total_loses']++;
									$long ? $results[$strategy_name]['total_longs']++ : $results[$strategy_name]['total_shorts']++;
									if($result['time'] == $endmin) {
										$results[$strategy_name]['timeout_loses']++;
									}
									$results[$strategy_name]['wins_plus_loses'] += $result['win'] ? 1 : -1;
//									$min = $result['time'];
								}
							}
						}
					}
				}
			}


			file_put_contents('/tmp/results_unsorted', print_r($results,1));
	//		echo "\n\nFinished at ".date('Y-m-d H:i:s', $end_min)."... Wins: $wins, Loses: $loses ... lose from timeout: $timeout";


			$percs = [];
			foreach($results as $strategy_name=>$data) {
				if($results[$strategy_name]['positions_count']) {
					$results[$strategy_name]['% win'] = ((($results[$strategy_name]['total_wins']) / $results[$strategy_name]['positions_count']) * 100);
				}
				if($results[$strategy_name]['total_longs']) {
					$results[$strategy_name]['% LONG win'] = ((($results[$strategy_name]['long_wins']) / $results[$strategy_name]['total_longs']) * 100);
				}
				if($results[$strategy_name]['total_shorts']) {
					$results[$strategy_name]['% SHORT win'] = ((($results[$strategy_name]['short_wins']) / $results[$strategy_name]['total_shorts']) * 100);
				}
				$percs[] = $results[$strategy_name]['% win'];
			}
			array_multisort($percs, $results);

			file_put_contents('/tmp/results', print_r($results,1));
			print_r($results);

			$all_results[$take] = $results;
		}
		file_put_contents('/tmp/allresults', print_r($all_results,1));
    }


}
