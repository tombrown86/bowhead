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
	protected $description = '';
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

		$instruments = ['EUR_USD'];
		$util = new Util\BrokersUtil();
		$wc = new Util\Whaleclub($this->instrument);
		$console = new \Bowhead\Util\Console();
		$indicators = new \Bowhead\Util\Indicators();

	        $cand = new Util\Candles();
	        $ind = new Util\Indicators();

		$this->wc = $wc;

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
				$signals = $this->signals(1, 0, [$instrument], $data);

				$result = $this->check_for_special_combo($instrument, $indicators, $candles);

				$direction = 0;
				if($result['signal'] == 'long') {
					$direction = 1;
					echo "\n-$instrument: Found long signal... ".print_r($result, 1);
				} else {
					$direction = -1; 
                                        echo "\n-$instrument: Found short signal... ".print_r($result, 1);
				}

				if($direction != 0) {
					$price = $wc->getPrice(str_replace(['/', '_'], '-', $instrument));
					$current_price = $price['price'];

					if (isset($this->last_order_bounds[$instrument]) && $current_price > $this->last_order_bounds[$instrument][0] && $current_price < $this->last_order_bounds[$instrument][1]) {
						echo ", strong signal but current price $current_price within bounds of last order (" . $this->last_order_bounds[$instrument][0] . " - " . $this->last_order_bounds[$instrument][1] . ")..\n";
					} else {
						echo ", Strong signal! .. current price found: $current_price..\n\n";
						$this->last_order_bounds[$instrument] = null;
						$fibs = $this->calcFibonacci($data);
						if (is_numeric($current_price) && $current_price > 0) {
							if ($direction < 0) {
								echo "$instrument: Going short..\n";

								$console->buzzer();
                                                                $take_profit = $fibs['S2'];
                                                                $stop_loss = $fibs['R2'];
								
								$order = [
									'direction' => 'short'
									, 'market' => str_replace(['/', '_'], '-', $instrument)
									, 'leverage' => 222
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
								echo "$instrument: Going long..\n";
                                                                $take_profit = $fibs['R2'];
                                                                $stop_loss = $fibs['S2'];

								$console->buzzer();
								$stop_loss =  $current_price - 150;
								$take_profit =  $current_price + 150;
								$order = [
									'direction' => 'long'
									, 'market' => str_replace(['/', '_'], '-', $instrument)
									, 'leverage' => 222
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
