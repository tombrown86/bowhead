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

    use Signals, Strategies, CandleMap, OHLC, Pivots; // add our traits

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'bowhead:wc_experiment';

	/**
	 * @var string
	 */
	protected $instrument = 'BTC-USD';

	/**
	 * @var
	 */
	protected $wc;

	/**
	 * @var
	 */
	protected $positions;

	/**
	 * @var
	 */
	protected $positions_time;

	/**
	 * @var
	 * positions attached to a indicator
	 */
	protected $indicator_positions;

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Binary options strategy bot';
	protected $order_cooloff;
	protected $last_order_bounds = [];

	/**
	 * @return int
	 */
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

		$instruments = ['BTC/USD', /*'ETH-USD'*/];
		$util = new Util\BrokersUtil();
		$wc = new Util\Whaleclub($this->instrument);
		$console = new \Bowhead\Util\Console();
		$indicators = new \Bowhead\Util\Indicators();

        $cand        = new Util\Candles();
        $ind         = new Util\Indicators();

		
		$this->wc = $wc;
//		register_shutdown_function(array($this, 'shutdown'));  //

		
		/**
		 *  Enter a loop where we check the strategy every minute.
		 */
		while (1) {
			if (ord(fgetc(STDIN)) == 113) { // try to catch keypress 'q'
				echo "QUIT detected...";
				return null;
			}
			echo "\n";

			foreach ($instruments as $instrument) {
				$data = $this->getRecentData($instrument, 200);
				
				$candles = $cand->allCandles($instrument, $data);
				$indicators = $ind->allSignals($instrument, $data);
				$signals = $this->signals(1, 0, [$instrument]);
				
				unset($indicators['ma']); // not needed here.	
				
				$direction = 0;
				if(isset($candles['current']) && count($candles['current'])) {
					foreach($candles['current'] as $candle_name=>$candle_value) {
						if($candle_value > 1) {
							if($indicators['adx'] > 1 && $signals['BTC/USD']['er'] > 1) {
								$direction = 1;
							}
							break;
						} else if($candle_value < 1) {
							if($indicators['adx'] < 1 && $signals['BTC/USD']['er'] < 1) {
								$direction = -1;
							}
							break;
						}
					}
				}
				
				echo "\n-$instrument: \$indicators['adx']: $indicators[adx],  \$signals['BTC/USD']['er']: ".$signals['BTC/USD']['er'].",  \$candles['current']: ".print_r(@$candles['current'], 1)." .... \$direction=$direction ... ";
				
				if($direction != 0) {
					$price = $wc->getPrice(str_replace('/', '-', $instrument));
					$current_price = $price['price'];
					
					if (isset($this->last_order_bounds[$instrument]) && $current_price > $this->last_order_bounds[$instrument][0] && $current_price < $this->last_order_bounds[$instrument][1]) {
						echo ", strong signal but current price $current_price within bounds of last order (" . $this->last_order_bounds[$instrument][0] . " - " . $this->last_order_bounds[$instrument][1] . ")..\n";
					} else {
						echo ", Strong signal! .. current price found: $current_price..\n\n";
						$this->last_order_bounds[$instrument] = null;
						if (is_numeric($current_price) && $current_price > 0) {
							echo "$instrument: Going short..\n";
							if ($direction < 0) {
								$console->buzzer();
								$stop_loss = $current_price + 150;
								$take_profit = $current_price - 150;
								$order = [
									'direction' => 'short'
									, 'market' => str_replace('/', '-', $instrument)
									, 'leverage' => 5
									, 'stop_loss' => $stop_loss
									, 'take_profit' => $take_profit
									, 'size' => 0.2
								#	, 'entry_price' => $current_price
								];
								print_r($order);
								$position = $wc->positionNew($order);
								$console->colorize("\n$instrument: OPENED NEW SHORT POSIITION");
								print_r($position);
								if(isset($position['entered_at'])) {
									$this->last_order_bounds[$instrument] = [$take_profit, $stop_loss];
								}
							}

							if ($direction > 0) {
								$console->buzzer();
								$stop_loss =  $current_price - 150;
								$take_profit =  $current_price + 150;
								$order = [
									'direction' => 'long'
									, 'market' => str_replace('/', '-', $instrument)
									, 'leverage' => 5
									, 'stop_loss' => $stop_loss
									, 'take_profit' => $take_profit
									, 'size' => 0.2
																		#        , 'entry_price' => $current_price

								];
								$position = $wc->positionNew($order);
								$console->colorize("\n$instrument: OPENED NEW LONG POSIITION");
								print_r($position);
																		if(isset($position['entered_at'])) {
																				$this->last_order_bounds[$instrument] = [$stop_loss, $take_profit];
																		}
							}
							if(isset($position['entered_at'])) {
								echo "$instrument: Created position..\n\n... no new positions until outside bounds: " . print_r($this->last_order_bounds, 1) . "\n\n\n";
							} else {
								echo "$instrument: Position not created...! \n";
							}
						}
					}
				}
				
			}
			sleep(8);
		}
	}

}
