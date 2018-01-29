<?php

namespace Bowhead\Console\Commands;

use Bowhead\Console\Kernel;
use Bowhead\Traits\OHLC;
use Illuminate\Console\Command;
use Bowhead\Util;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use AndreasGlaser\PPC\PPC; // https://github.com/andreas-glaser/poloniex-php-client

/**
 * Class ExampleCommand
 * @package Bowhead\Console\Commands
 */

class WhaleClubFromTradingViewSignal extends Command {

	use OHLC;

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'bowhead:wc_from_tv_signal';

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

		$instruments = ['BTC-USD', /*'ETH-USD'*/];
		$util = new Util\BrokersUtil();
		$wc = new Util\Whaleclub($this->instrument);
		$console = new \Bowhead\Util\Console();
		$indicators = new \Bowhead\Util\Indicators();

		$this->wc = $wc;
		register_shutdown_function(array($this, 'shutdown'));  //

		/**
		 *  Enter a loop where we check the strategy every minute.
		 */
		while (1) {
			if (!(date('i') >= 1 && date('i') <= 45)) {
				echo '.';
			} else {

				if (ord(fgetc(STDIN)) == 113) { // try to catch keypress 'q'
					echo "QUIT detected...";
					return null;
				}
				echo "\n";

				foreach ($instruments as $instrument) {
					$underbought = $overbought = 0;

					//call trading view
					$url = 'https://scanner.tradingview.com/crypto/scan';
					$bodyData = '{"filter":[{"left":"name","operation":"nempty"},{"left":"name,description","operation":"match","right":"' . str_replace('-', '', $instrument) . '"}],"symbols":{"query":{"types":[]}},"columns":["name","close|15","change|15","change_abs|15","high|15","low|15","volume|15","Recommend.All|15","exchange","description","name","subtype"],"sort":{"sortBy":"name","sortOrder":"desc"},"options":{"lang":"en"},"range":[0,150]}';

					$ch = curl_init($url);

					//				$data = json_encode(array("Host"=> "scanner.tradingview.com", "Origin" =>"https://www.tradingview.com", "Referer" =>"https://www.tradingview.com/cryptocurrency-signals/", "Content-Type" =>'application/x-www-form-urlencoded; charset=UTF-8', "User-Agend" => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:54.0) Gecko/20100101 Firefox/54.0'));


					curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyData);

					//				curl_setopt( $ch, CURLOPT_HTTPHEADER,  array("headerdata: ".$data.",Content-Type:application/json"));

					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

					$result = curl_exec($ch);

					curl_close($ch);

					$obj = json_decode($result);


					$signal = 0;
					$used_results = 0;

					foreach ($obj->data as $result) {

						if (substr($result->s, -7) == ':' . str_replace('-', '', $instrument)) {
							$signal += $result->d[7];
							$used_results++;
						}
					}
					if ($used_results > 0) {
						$signal /= count($used_results);

						echo "$instrument: Detected signal $signal from $used_results results ";


						//                $recentData = $this->getRecentData($instrument);
						//
	//                $cci = $indicators->cci($instrument, $recentData);
						//                $cmo = $indicators->cmo($instrument, $recentData);
						//                $mfi = $indicators->mfi($instrument, $recentData);
						//
	//                /** instrument is overbought, we will short */
						//                if ($cci == -1 && $cmo == -1 && $mfi == -1) {
						//                    $overbought = 1;
						//                }
						//                /** It is underbought, we will go LONG */
						//                if ($cci == 1 && $cmo == 1 && $mfi == 1) {
						//                    $underbought = 1;
						//                }
						//
	//                /**
						//                 *   THIS SECTION IS FOR DISPLAY
						//                 */
						//                $line = $console->colorize(" Signals for $instrument:");
						//                $line .= $console->colorize(str_pad("cci:$cci", 11), $this->doColor($cci));
						//                $line .= $console->colorize(str_pad("cmo:$cmo", 9), $this->doColor($cmo));
						//                $line .= $console->colorize(str_pad("mfi:$mfi", 9), $this->doColor($mfi));
						//                $line .= ($overbought ? $console->colorize(' overbought', 'light_red') : $console->colorize(' overbought', 'dark'));
						//                $line .= ($underbought ? $console->colorize(' underbought', 'light_green') : $console->colorize(' underbought', 'dark'));
						//                echo "$line";
						/**
						 *  DISPLAY DONE
						 */
						if (abs($signal) >= 3.5) {
							$price = $wc->getPrice($instrument);
							print_r($price);
							$current_price = $price['price'];
						

							if (isset($this->last_order_bounds[$instrument]) && $current_price > $this->last_order_bounds[$instrument][0] && $current_price < $this->last_order_bounds[$instrument][1]) {
								echo ", strong signal but current price $current_price within bounds of last order (" . $this->last_order_bounds[$instrument][0] . " - " . $this->last_order_bounds[$instrument][1] . ")..\n";
							} else {
								echo ",  Strong signal! .. current price found: $current_price..\n\n";
								$this->last_order_bounds[$instrument] = null;
								if (is_numeric($current_price) && $current_price > 0) {
									echo "$instrument: Going short..\n";
									if ($signal < 0) {
										$console->buzzer();
										$stop_loss = $current_price + ($current_price / 80);
										$take_profit = $current_price - ($current_price / 80);
										$order = [
											'direction' => 'short'
											, 'market' => $instrument
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

									if ($signal > 0) {
										$console->buzzer();
										$stop_loss =  $current_price - ($current_price / 80);
										$take_profit =  $current_price + ($current_price / 80);
										$order = [
											'direction' => 'long'
											, 'market' => $instrument
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
									/* $i = 10;
									  echo "Waiting $i mins:";
									  for(; $i>=0; $i--) {
									  echo ".";
									  sleep(60);
									  }
									  echo "\n\n"; */
								}
							}
						}
					}
				}
			}
			sleep(8);
		}
	}

}
