<?php

/**
 * Created by PhpStorm.
 * User: joeldg
 * Date: 6/26/17
 * Time: 4:03 PM
 */

namespace Bowhead\Traits;

use Illuminate\Support\Facades\DB;

trait OHLC {

	/**
	 * @param $ticker
	 *
	 * @return bool
	 */
	public function markOHLC($ticker, $bf = false, $bf_pair = 'BTC/USD') {
		if ($bf == 'raw') {
			$instrument = $bf_pair;
			extract($ticker);
			$now = strtotime($ctime);

			/** tick table update */
			$ins = \DB::insert("
				INSERT INTO bowhead_ohlc_tick
				(`instrument`, `timeid`, `open`, `high`, `low`, `close`, `volume`, volume2, ctime)
				VALUES
				('$bf_pair', $timeid, $open, $high, $low, $close, $volume, $volume2, '$ctime')
				ON DUPLICATE KEY UPDATE
				`high`   = CASE WHEN `high` < VALUES(`high`) THEN VALUES(`high`) ELSE `high` END,
				`low`    = CASE WHEN `low` > VALUES(`low`) THEN VALUES(`low`) ELSE `low` END,
				`volume` = VALUES(`volume`),
				`close`  = VALUES(`close`)
			");
		} else {
			$now = time();
			$timeid = date('YmdHis'); // 20170530152259 unique for date
			$ctime = date('Y-m-d H:i:s');
			if ($bf) {
				/** Bitfinex websocked */
				$last_price = $ticker[7];
				$volume = $ticker[8];
				$instrument = $bf_pair;

				/** if timeid passed, we use it, otherwise use generated one.. */
				$timeid = ($ticker['timeid'] ?? $timeid);
			} else {
				/** Oanda websocket */
				$last_price = $ticker['tick']['bid'];
				$instrument = $ticker['tick']['instrument'];
				$volume = 0;
			}

			/** tick table update */
			$ins = \DB::insert("
				INSERT INTO bowhead_ohlc_tick
				(`instrument`, `timeid`, `open`, `high`, `low`, `close`, `volume`, `ctime`)
				VALUES
				('$instrument', $timeid, $last_price, $last_price, $last_price, $last_price, $volume, '$ctime')
				ON DUPLICATE KEY UPDATE
				`high`   = CASE WHEN `high` < VALUES(`high`) THEN VALUES(`high`) ELSE `high` END,
				`low`    = CASE WHEN `low` > VALUES(`low`) THEN VALUES(`low`) ELSE `low` END,
				`volume` = VALUES(`volume`),
				`close`  = VALUES(`close`)
			");
		}


		/** 1m table update * */
		$open1 = null;
		$close1 = null;
		$high1 = null;
		$low1 = null;
		$last1timeid = 0;
		$timeid = date("YmdHi", strtotime($timeid));
		
		$table = 'bowhead_ohlc_1m';
		if((int)$timeid < 201802010000) { // (I did some table sharding for my training dataset)
			if($instrument == 'EUR/USD')
				$table .= '_eurusd';
			if($instrument == 'GBP/USD')
				$table .= '_gbpusd';
			if($instrument == 'GBP/JPY')
				$table .= '_gbpjpy';
			if($instrument == 'EUR/GBP')
				$table .= '_eurgbp';
		}
		
		$last1m = \DB::table($table)->select(DB::raw('MAX(timeid) AS timeid'))
				->where('instrument', $instrument)
				->where('timeid', '<', $timeid)
				->get();

		foreach ($last1m as $last1) {
			$last1timeid = $last1->timeid;
			$last1timeid = date("YmdHi", strtotime($last1timeid));
		}

		if ($last1timeid < $timeid) {

			/* Get High and Low from ticker data for insertion */
			$last1timeids = date("YmdHis", strtotime(date("YmdHi", strtotime("-1 minutes", $now))));
			$accum1ma = \DB::table('bowhead_ohlc_tick')->select(DB::raw('MAX(high) as high, MIN(low) as low'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last1timeids)
					->where('timeid', '<=', ($last1timeids + 59))
					->get();

			foreach ($accum1ma as $accum1a) {
				$high1 = $accum1a->high;
				$low1 = $accum1a->low;
			}


			/* Get Open price from ticker data and last minute */
			$accum1mb = \DB::table('bowhead_ohlc_tick')->select(DB::raw('open AS open'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last1timeids)
					->where('timeid', '<=', ($last1timeids + 59))
					->limit(1)
					->get();

			foreach ($accum1mb as $accum1b) {
				$open1 = $accum1b->open;
			}

			/* Get close price from ticker data and last minute */
			$accum1mc = \DB::table('bowhead_ohlc_tick')->select(DB::raw('close AS close'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last1timeids)
					->where('timeid', '<=', ($last1timeids + 59))
					->orderBy('ctime', 'desc')
					->limit(1)
					->get();

			foreach ($accum1mc as $accum1c) {
				$close1 = $accum1c->close;
			}

//die("$open1 $close1 ");
			if ($open1 && $close1 && $high1 && $low1) {

				$ins = \DB::insert("
            INSERT INTO bowhead_ohlc_1m 
            (`instrument`, `timeid`, `open`, `high`, `low`, `close`, `volume`, ctime)
            VALUES
            ('$instrument', $timeid, $open1, $high1, $low1, $close1, $volume, '$ctime')
            ON DUPLICATE KEY UPDATE 
            `high`   = CASE WHEN `high` < VALUES(`high`) THEN VALUES(`high`) ELSE `high` END,
            `low`    = CASE WHEN `low` > VALUES(`low`) THEN VALUES(`low`) ELSE `low` END,
            `volume` = VALUES(`volume`),
            `close`  = VALUES(`close`)
        ");
			}
		}

		/** 5m table update  * */
		$open5 = null;
		$close5 = null;
		$high5 = null;
		$low5 = null;

		$last5m = \DB::table('bowhead_ohlc_5m')->select(DB::raw('MAX(timeid) AS timeid'))
				->where('instrument', $instrument)
				->where('timeid', '<', $timeid)
				->get();
		foreach ($last5m as $last5) {
			$last5timeid = $last5->timeid;
			$last5timeid = date("YmdHi", strtotime("+4 minutes", strtotime($last5timeid)));
		}
		if ($last5timeid < $timeid) {
			/* Get High and Low from 1m data for insertion */
			$last5timeids = date("YmdHi", strtotime("-5 minutes", $now));
			$accum5ma = \DB::table('bowhead_ohlc_1m')->select(DB::raw('MAX(high) as high, MIN(low) as low'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last5timeids)
					->where('timeid', '<=', ($timeid))
					->get();

			foreach ($accum5ma as $accum5a) {
				$high5 = $accum5a->high;
				$low5 = $accum5a->low;
			}

			/* Get Open price from 1m data and last 5 minutes */
			$accum5mb = \DB::table('bowhead_ohlc_1m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last5timeids)
					->where('timeid', '<=', ($timeid))
					->limit(1)
					->get();
			foreach ($accum5mb as $accum5b) {
				$open5 = $accum5b->open;
			}

			/* Get Close price from 1m data and last 5 minutes */
			$accum5mc = \DB::table('bowhead_ohlc_1m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last5timeids)
					->where('timeid', '<=', ($timeid))
					->orderBy('ctime', 'desc')
					->limit(1)
					->get();
			foreach ($accum5mc as $accum5c) {
				$close5 = $accum5c->close;
			}
			if ($open5 && $close5 && $low5 && $high5) {
				$ins = \DB::insert("
            INSERT INTO bowhead_ohlc_5m 
            (`instrument`, `timeid`, `open`, `high`, `low`, `close`, `volume`, ctime)
            VALUES
            ('$instrument', $timeid, $open5, $high5, $low5, $close5, $volume, '$ctime')
            ON DUPLICATE KEY UPDATE 
            `high`   = CASE WHEN `high` < VALUES(`high`) THEN VALUES(`high`) ELSE `high` END,
            `low`    = CASE WHEN `low` > VALUES(`low`) THEN VALUES(`low`) ELSE `low` END,
            `volume` = VALUES(`volume`),
            `close`  = VALUES(`close`)
        ");
			}
		}

		/** 15m table update * */
		$open15 = null;
		$close15 = null;
		$high15 = null;
		$low15 = null;

		$last15m = \DB::table('bowhead_ohlc_15m')->select(DB::raw('MAX(timeid) AS timeid'))
				->where('instrument', $instrument)
				->where('timeid', '<', $timeid)
				->get();
		foreach ($last15m as $last15) {
			$last15timeid = $last15->timeid;
			$last15timeid = date("YmdHi", strtotime("+14 minutes", strtotime($last15timeid)));
		}
		if ($last15timeid < $timeid) {
			/* Get High and Low from 5m data for insertion */
			$last15timeids = date("YmdHi", strtotime("-15 minutes", $now));
			$accum15ma = \DB::table('bowhead_ohlc_5m')->select(DB::raw('MAX(high) as high, MIN(low) as low'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last15timeids)
					->where('timeid', '<=', ($timeid))
					->get();

			foreach ($accum15ma as $accum15a) {
				$high15 = $accum15a->high;
				$low15 = $accum15a->low;
			}

			/* Get Open price from 5m data and last 15 minutes */
			$accum15mb = \DB::table('bowhead_ohlc_5m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last15timeids)
					->where('timeid', '<=', ($timeid))
					->limit(1)
					->get();
			foreach ($accum15mb as $accum15b) {
				$open15 = $accum15b->open;
			}

			/* Get Close price from 5m data and last 15 minutes */
			$accum15mc = \DB::table('bowhead_ohlc_5m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last15timeids)
					->where('timeid', '<=', ($timeid))
					->orderBy('ctime', 'desc')
					->limit(1)
					->get();
			foreach ($accum15mc as $accum15c) {
				$close15 = $accum15c->close;
			}
			if ($open15 && $close15 && $low15 && $high15) {
				$ins = \DB::insert("
            INSERT INTO bowhead_ohlc_15m 
            (`instrument`, `timeid`, `open`, `high`, `low`, `close`, `volume`, ctime)
            VALUES
            ('$instrument', $timeid, $open15, $high15, $low15, $close15, $volume, '$ctime')
            ON DUPLICATE KEY UPDATE 
            `high`   = CASE WHEN `high` < VALUES(`high`) THEN VALUES(`high`) ELSE `high` END,
            `low`    = CASE WHEN `low` > VALUES(`low`) THEN VALUES(`low`) ELSE `low` END,
            `volume` = VALUES(`volume`),
            `close`  = VALUES(`close`)
        ");
			}
		}

		/** 30m table update * */
		$open30 = null;
		$close30 = null;
		$high30 = null;
		$low30 = null;

		$last30m = \DB::table('bowhead_ohlc_30m')->select(DB::raw('MAX(timeid) AS timeid'))
				->where('instrument', $instrument)
				->where('timeid', '<', $timeid)
				->get();
		foreach ($last30m as $last30) {
			$last30timeid = $last30->timeid;
			$last30timeid = date("YmdHi", strtotime("+29 minutes", strtotime($last30timeid)));
		}
		if ($last30timeid < $timeid) {
			/* Get High and Low from 15m data for insertion */
			$last30timeids = date("YmdHi", strtotime("-30 minutes", $now));
			$accum30ma = \DB::table('bowhead_ohlc_15m')->select(DB::raw('MAX(high) as high, MIN(low) as low'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last30timeids)
					->where('timeid', '<=', ($timeid))
					->get();

			foreach ($accum30ma as $accum30a) {
				$high30 = $accum30a->high;
				$low30 = $accum30a->low;
			}

			/* Get Open price from 15m data and last 30 minutes */
			$accum30mb = \DB::table('bowhead_ohlc_15m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last30timeids)
					->where('timeid', '<=', ($timeid))
					->limit(1)
					->get();
			foreach ($accum30mb as $accum30b) {
				$open30 = $accum30b->open;
			}

			/* Get Close price from 15m data and last 30 minutes */
			$accum30mc = \DB::table('bowhead_ohlc_15m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last30timeids)
					->where('timeid', '<=', ($timeid))
					->orderBy('ctime', 'desc')
					->limit(1)
					->get();
			foreach ($accum30mc as $accum30c) {
				$close30 = $accum30c->close;
			}
			if ($open30 && $close30 && $low30 && $high30) {
				$ins = \DB::insert("
            INSERT INTO bowhead_ohlc_30m 
            (`instrument`, `timeid`, `open`, `high`, `low`, `close`, `volume`, ctime)
            VALUES
            ('$instrument', $timeid, $open30, $high30, $low30, $close30, $volume, '$ctime')
            ON DUPLICATE KEY UPDATE 
            `high`   = CASE WHEN `high` < VALUES(`high`) THEN VALUES(`high`) ELSE `high` END,
            `low`    = CASE WHEN `low` > VALUES(`low`) THEN VALUES(`low`) ELSE `low` END,
            `volume` = VALUES(`volume`),
            `close`  = VALUES(`close`)
        ");
			}
		}

		/** 1h table update * */
		$open60 = null;
		$close60 = null;
		$high60 = null;
		$low60 = null;

		$last60m = \DB::table('bowhead_ohlc_1h')->select(DB::raw('MAX(timeid) AS timeid'))
				->where('instrument', $instrument)
				->where('timeid', '<', $timeid)
				->get();
		foreach ($last60m as $last60) {
			$last60timeid = $last60->timeid;
			$last60timeid = date("YmdHi", strtotime("+59 minutes", strtotime($last60timeid)));
		}
		if ($last60timeid < $timeid) {
			/* Get High and Low from 30m data for insertion */
			$last60timeids = date("YmdHi", strtotime("-60 minutes", $now));
			$accum60ma = \DB::table('bowhead_ohlc_30m')->select(DB::raw('MAX(high) as high, MIN(low) as low'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last60timeids)
					->where('timeid', '<=', ($timeid))
					->get();

			foreach ($accum60ma as $accum60a) {
				$high60 = $accum60a->high;
				$low60 = $accum60a->low;
			}

			/* Get Open price from 30m data and last 60 minutes */
			$accum60mb = \DB::table('bowhead_ohlc_30m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last60timeids)
					->where('timeid', '<=', ($timeid))
					->limit(1)
					->get();
			foreach ($accum60mb as $accum60b) {
				$open60 = $accum60b->open;
			}

			/* Get Close price from 30m data and last 60 minutes */
			$accum60mc = \DB::table('bowhead_ohlc_30m')->select(DB::raw('*'))
					->where('instrument', $instrument)
					->where('timeid', '>=', $last60timeids)
					->where('timeid', '<=', ($timeid))
					->orderBy('ctime', 'desc')
					->limit(1)
					->get();
			foreach ($accum60mc as $accum60c) {
				$close60 = $accum60c->close;
			}
			if ($open60 && $close60 && $low60 && $high60) {
				$ins = \DB::insert("
            INSERT INTO bowhead_ohlc_1h 
            (`instrument`, `timeid`, `open`, `high`, `low`, `close`, `volume`, ctime)
            VALUES
            ('$instrument', $timeid, $open60, $high60, $low60, $close60, $volume, '$ctime')
            ON DUPLICATE KEY UPDATE 
            `high`   = CASE WHEN `high` < VALUES(`high`) THEN VALUES(`high`) ELSE `high` END,
            `low`    = CASE WHEN `low` > VALUES(`low`) THEN VALUES(`low`) ELSE `low` END,
            `volume` = VALUES(`volume`),
            `close`  = VALUES(`close`)
        ");
			}
		}

		return true;
	}

	/**
	 * @param $datas
	 *
	 * @return array
	 */
	public function organizePairData($datas) {
		$ret['date'] = [];
		$ret['low'] = [];
		$ret['high'] = [];
		$ret['open'] = [];
		$ret['close'] = [];
		$ret['volume'] = [];

		$ret = array();
		foreach ($datas as $data) {
			$ret['date'][] = $data->buckettime;
			$ret['low'][] = $data->low;
			$ret['high'][] = $data->high;
			$ret['open'][] = $data->open;
			$ret['close'][] = $data->close;
			$ret['volume'][] = $data->volume;
		}
		foreach ($ret as $key => $rettemmp) {
			$ret[$key] = array_reverse($rettemmp);
		}
		return $ret;
	}

	
	
	public function winOrLoseTest() {
		$wc_trades = \Bowhead\Models\wc_trade::all();
		
		$out = [];
		foreach($wc_trades as $wc_trade) {
			$wc_trade = $wc_trade->toArray();
			
			$created_at = $wc_trade['created_at'];
			$long = $wc_trade['direction'] != 'sell';
			
			$wc_trade['OPEN HRS'] = ($wc_trade['closed_at']-$wc_trade['entered_at'])/3600;
//			$wc_trade['% PROFIT'] = ($long ? 100 : -100) * $wc_trade['close_price'] / $wc_trade['entry_price'];
			
			$spread = ['EUR_USD' => 0.01, 'EUR_GBP' => 0.03, 'GBP_USD' => 0.06, /* 'GBP_JPY' => */];
			$spread = $spread[str_replace('-','_',$wc_trade['market'])];
			
			print_r($wc_trade);
			print_r($this->getWinOrLoseWC(str_replace('-','_',$wc_trade['market']), $created_at, $created_at + 24*60*60, $long, $wc_trade['take_profit'], $wc_trade['stop_loss'], $wc_trade['entry_price']/*$wc_trade['current_price']*/, 222, $spread));
			echo "\n\n\n---------------------\n\n";
		}
		
//		print_r($out);
		die;
		//return $this->getWinOrLose('GBP_USD', $time, $time + 24*60*60, 0, 1.3228000000, 1.3222900000, 1.3228000000, $leverage = 222, 0.1);
	}
	
	public function getWinOrLose($instrument, $time, $etime, $long, $take, $stop, $entry = NULL, $leverage = 1, $spread_perc = 0) {
		// if I understand this right, we lose ~ half spread on entry (due to cost to buy/sell) .. effectively offsetting our entry position
		// The take and stop loss values used to calc the profit are not adjusted, instead the TP or SL won't trigger until we hit actual price +/- remaining spread adjustment
		$adjusted_entry = $entry ? ($long ? ($entry / 100) * (100 + ($spread_perc / 2)) : ($entry / 100) * (100 - ($spread_perc / 2))) : NULL;
		$adjusted_take = $long ? ($take / 100) * (100 + ($spread_perc / 2)) : ($take / 100) * (100 - ($spread_perc / 2));
		$adjusted_stop = $long ? ($stop / 100) * (100 - ($spread_perc / 2)) : ($stop / 100) * (100 + ($spread_perc / 2));

		$table = 'bowhead_ohlc_tick';
		if((int)$time < strtotime('2018-02-01 00:00:00')) { //// (I did some table sharding for my training dataset)
			if($instrument == 'EUR/USD')
				$table .= '_eurusd';
			if($instrument == 'GBP/USD')
				$table .= '_gbpusd';
			if($instrument == 'GBP/JPY')
				$table .= '_gbpjpy';
			if($instrument == 'EUR/GBP')
				$table .= '_eurgbp';
		}
		
		$outcome_resultset = \DB::table($table)->select(DB::raw('*'))
				->where('instrument', $instrument)
				->where('ctime', '>', date('Y-m-d H:i:s', $time))
				->where('ctime', '<', date('Y-m-d H:i:s', $etime))
				->where(function($q) use ($long, $adjusted_take, $adjusted_stop) {
					$q->where('high', '>=', $long ? $adjusted_take : $adjusted_stop)
					->orWhere('low', '<=', $long ? $adjusted_stop : $adjusted_take);
				})
				->orderBy('ctime')
				->limit(1)
				->get();
				
		// Temp: Added financing (experiment) based on WC rules
		$financing_perc = 0.05;
		$hours = floor(($etime - $time + 3600/2/*add half hr ass avg opening time*/)/3600);
		$financing_cost = ($financing_perc/100) * $hours / 24;
		
		foreach ($outcome_resultset as $outcome) {
			$win = !($long ? (float)$outcome->low <= $adjusted_stop : (float)$outcome->high >= $adjusted_stop);
						
			$profit = ($win ? abs($take - $adjusted_entry) : -abs($stop - $adjusted_entry)) - $financing_cost;
			$percentage_profit = empty($adjusted_entry) ? NULL : $leverage * ((($profit / $adjusted_entry) * 100));

			return [
				'win' => $profit > 0, // finance costs may make a "$win" actually a lose,
				'time' => strtotime($outcome->ctime),
				'percentage_profit' => $percentage_profit,
			];
		}

		return [
			'timeout' => true,
			'win' => false,
			'time' => $etime,
			'percentage_profit' => empty($adjusted_entry) ? NULL : -$leverage * ((abs($stop - $adjusted_entry) + $financing_cost) / $adjusted_entry) * 100,
		];
	}



	public function getWinOrLoseWC($instrument, $time, $etime, $long, $take, $stop, $entry = NULL, $leverage = 1, $spread_perc = 0) {
		$adjusted_entry = $entry ? ($long ? $entry + ($entry * $spread_perc / 100) : $entry - ($entry * $spread_perc / 100)) : NULL;
//		$adjusted_entry = $entry;//TEMP REMOVE
		
		// must adjust this more!
		$lose_spread = 0.012; // average realistic spread for losing trade!
		$expected_stop_close = $long ? $stop - ($stop * $lose_spread / 100) : $stop + ($stop * $lose_spread / 100);
		$expected_stop_exit = $long ? $expected_stop_close + ($expected_stop_close * $spread_perc / (2*100)) : $expected_stop_close - ($expected_stop_close * $spread_perc / (2*100));
		// not sure if also need to deduct half spread for realistic exit even with lose spread!

		$expected_take_close = $take;
		$expected_take_exit = $long ? $take + ($take * $spread_perc / (2*100)) : $take - ($take * $spread_perc / (2*100));

		print_r("\n\$entry: ".$entry);
		print_r("\n\$spread_perc: ".$spread_perc);
		print_r("\n\$adjusted_entry: ".$adjusted_entry);
		print_r("\n\$expected_stop_close: ".$expected_stop_close);
		print_r("\n\$expected_stop_exit: ".$expected_stop_exit);
		print_r("\n\$expected_take_close: ".$expected_take_close);
		print_r("\n\$expected_take_exit: ".$expected_take_exit);

		$table = 'bowhead_ohlc_tick';
		if((int)$time < strtotime('2018-02-01 00:00:00')) { //// (I did some table sharding for my training dataset)
			if($instrument == 'EUR/USD')
				$table .= '_eurusd';
			if($instrument == 'GBP/USD')
				$table .= '_gbpusd';
			if($instrument == 'GBP/JPY')
				$table .= '_gbpjpy';
			if($instrument == 'EUR/GBP')
				$table .= '_eurgbp';
		}
		
		$outcome_resultset = \DB::table($table)->select(DB::raw('*'))
				->where('instrument', $instrument)
				->where('ctime', '>', date('Y-m-d H:i:s', $time))
				->where('ctime', '<', date('Y-m-d H:i:s', $etime))
				->where(function($q) use ($long, $expected_take_exit, $expected_stop_exit) {
					$q->where('high', '>=', $long ? $expected_take_exit : $expected_stop_exit)
					->orWhere('low', '<=', $long ? $expected_stop_exit : $expected_take_exit);
				})
				->orderBy('ctime')
				->limit(1)
				->get();

		// Temp: Added financing (experiment) based on WC rules
		$size = 10000;
		$financing_perc = 0.05;
		$hours = floor(($etime - $time + 3600/2/*add half hr ass avg opening time*/)/3600);
		$financing_cost = 0;//($financing_perc/100) * $hours / 24;

		foreach ($outcome_resultset as $outcome) {
			$win = !($long ? (float)$outcome->low <= $expected_stop_exit : (float)$outcome->high >= $expected_stop_exit);
			$close = $win ? $expected_take_close : $expected_stop_close;
			$profit = ((($close-$adjusted_entry)/$adjusted_entry)*100*$leverage/$size/$size) * ($long ? 1 : -1);
			$profit -= $financing_cost;
			$percentage_profit = $profit * $size * $size;

			return [
				'win' => $profit > 0, // finance costs may make a "$win" actually a lose,
				'time' => strtotime($outcome->ctime),
				'profit' => $profit,
				'percentage_profit' => $percentage_profit,
			];
		}

                $close = $expected_stop_close;
                $profit = (($close-$adjusted_entry)/$adjusted_entry)*100*$leverage/$size/$size * ($long ? 1 : -1);
		$profit -= $financing_cost;

		return [
			'timeout' => true,
			'win' => false,
			'time' => $etime,
			'profit' => $profit,
			'percentage_profit' => $profit * $size * $size,
		];

	}

	/**
	 * @param string $pair
	 * @param int    $limit
	 * @param bool   $day_data
	 * @param int    $hour
	 * @param string $periodSize
	 * @param bool   $returnRS
	 *
	 * @return array
	 */
	public function getRecentData($pair = 'BTC/USD', $limit = 168, $day_data = false, $hour = 12, $periodSize = '1m', $returnRS = false, $current_time = null, $die_on_large_period = TRUE) {
		/**
		 *  we need to cache this as many strategies will be
		 *  doing identical pulls for signals.
		 */
		$key = 'recent::' . $pair . '::' . $limit . "::$day_data::$hour::$periodSize";

		// TODO improved caching
		if ($periodSize == '1m') {
			$variance = (int) 200;
		} else if ($periodSize == '5m') {
			$variance = (int) 375;
		} else if ($periodSize == '15m') {
			$variance = (int) 1125;
		} else if ($periodSize == '30m') {
			$variance = (int) 2250;
		} else if ($periodSize == '1h') {
			$variance = (int) 4500;
		} else if ($periodSize == '1d') {
			$variance = (int) 108000;
		}

#DB::enableQueryLog();


		$current_time = $current_time ? $current_time : time();
		$a = \DB::table('bowhead_ohlc_' . $periodSize)
//				->select(DB::raw('*, unix_timestamp(ctime) as buckettime'))
				->where('instrument', $pair)
				->where('timeid', '<=', date('YmdHi', $current_time))
				->orderby('timeid', 'DESC')
				->limit($limit)
				->get();
		echo 'getRecentData (' . $pair . '): ' . date(' Y-m-d H:i:s ', $current_time) . "\n";
//die(date('YmdHi', $current_time));
#echo  print_r(DB::getQueryLog(), 1);die;
#DB::disableQueryLog();



		$periods = [];
		$ptime = null;
		$validperiods = 0;

		foreach ($a as $i=>$ab) {
			$array = (array) $ab;
			$a[$i]->buckettime = $array['buckettime'] = strtotime($array['ctime']); // since mysql unix_timestamp attempt was returning timestamps an hour out (maybe due to BST?)
			$ftime = $array['buckettime'];
//			echo date('Y-m-d H:i:s', $current_time)."\n";
//			echo date('Y-m-d H:i:s', $ftime)."\n\n";
			if ($ptime == null) {
				$ptime = $ftime;
				$periodcheck = $current_time - $ptime;
				if ($die_on_large_period && $periodcheck > $variance) {
					echo "Most recent data is too old... \$periodcheck > \$variance ($periodcheck > $variance) ... \$current_time=$current_time, \$ptime=$ptime, \$variance=$variance)";
					die();
				}
				$periods[] = $periodcheck;
			} else {
				/** Check for missing periods * */
				#echo 'Past Time is '.$ptime.' and current time is '.$ftime."\n";
				$periodcheck = $ptime - $ftime;
				if ($die_on_large_period  && (int) $periodcheck > (int) $variance) {
					echo $periodcheck . ' > ' . $variance . ' ' . date('Y-m-d H i s', $ftime) . '  YOU HAVE ' . $validperiods . ' PERIODS OF VALID PRICE DATA OUT OF ' . $limit . '. Please ensure price sync is running and wait for additional data to be logged before trying again. Additionally you could use a smaller time period if available.' . "\n";
					die();
				}
				$periods[] = $periodcheck;
				$validperiods++;
			}
			$ptime = $ftime;
		}
		if ($returnRS) {
			$ret = $a;
		} else {
			$ret = $this->organizePairData($a);
			$ret['periods'] = array_reverse($periods);
		}
		//todo: \Cache::put($key, $ret, 2);
		return $ret;
	}

}
