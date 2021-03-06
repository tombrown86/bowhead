<?php

/**
 * Created by PhpStorm.
 * User: joeldg
 * Date: 7/1/17
 * Time: 8:34 PM
 */

namespace Bowhead\Traits;

use Bowhead\Util\Candles;

trait CandleMap {

	/**
	 * @var array
	 *  candles which have been identified to act
	 *  counter to their purpose, the percentage value is the percentage of
	 *  the counter purpose flip:
	 *  i.e 3linestrike 84% of the time is a bullish reversal
	 */
	protected $counter_purpose = [
		'bear' => [
			'2crows' => 60
			, '3linestrike' => 84
			, 'advanceblock' => 64
			, 'dojistar' => 69
			, 'hangingman' => 59
			, 'stalledpattern' => 77
			, 'tasukigap' => 54
			, 'thrusting' => 57
			, 'upsidegap2crows' => 60
			, 'sidegap3methods' => 62
		]
		, 'bull' => [
			'3linestrike' => 63
			, 'concealbabyswall' => 75
			, 'dojistar' => 64
			, 'homingpigeon' => 56
			, 'matchinglow' => 61
			, 'sticksandwich' => 62
			, 'unique3river' => 60
			, 'xsidegap3methods' => 59
		]
	];

	/**
	 * @var array
	 * bearish and bullish price reversal patterns with
	 * percentages of actual reversals.
	 */
	protected $price_reversal = [
		'bear' => [
			'2crows' => 60
			, '3blackcrows' => 78
			, '3inside' => 60
			, '3outside' => 69
			, 'abandonedbaby' => 69
			, 'advanceblock' => 64
			, 'belthold' => 68
			, 'breakaway' => 63
			, 'counterattack' => 60
			, 'darkcloudcover' => 60
			, 'doji' => 52
			, 'engulfing' => 79
			, 'eveningdojistar' => 71
			, 'eveningstar' => 72
			, 'harami' => 53
			, 'hikkake' => 50
			, 'identical3crows' => 78
			, 'kicking' => 54
			, 'shootingstar' => 59
			, 'stalledpattern' => 77
			, 'tristar' => 52
		]
		, 'bull' => [
			'3inside' => 65
			, '3outside' => 75
			, '3starsinsouth' => 86
			, '3whitesoldiers' => 82
			, 'abandonedbaby' => 70
			, 'belthold' => 71
			, 'breakaway' => 59
			, 'counterattack' => 66
			, 'doji' => 51
			, 'engulfing' => 63
			, 'hammer' => 60
			, 'harami' => 53
			, 'hikkake' => 52
			, 'homingpigeon' => 56
			, 'invertedhammer' => 65
			, 'kicking' => 53
			, 'ladderbottom' => 56
			, 'morningdojistar' => 76
			, 'morningstar' => 78
			, 'piercing' => 64
			, 'sticksandwich' => 62
			, 'takuri' => 66
			, 'tristar' => 60
			, 'unique3river' => 60
		]
	];

	/**
	 * @var array
	 * These candles indicate indecision
	 * if these candles are present, then avoid entering positions.
	 */
	protected $indecision = [
		'gravestonedoji'
		, 'longleggeddoji'
		, 'highwave'
		, 'rickshawman'
		, 'shortline'
		, 'spinningtop'
	];

	/**
	 * @var array
	 * price continuation candle patterns with percentages
	 */
	protected $price_continuation = [
		'bear' => [
			'closingmarubozu' => 52
			, 'hikkake' => 50
			, 'inneck' => 53
			, 'longline' => 53
			, 'marubozu' => 53
			, 'onneck' => 56
			, 'risefall3methods' => 71
			, 'separatinglines' => 63
		]
		, 'bull' => [
			'closingmarubozu' => 55
			, 'gapsidesidewhite' => 66
			, 'hikkake' => 50
			, 'longline' => 58
			, 'marubozu' => 56
			, 'mathold' => 78
			, 'risefall3methods' => 74
			, 'separatinglines' => 72
		]
	];

	/**
	 * @param $data
	 *
	 * @return mixed
	 *
	 *  returns array with weights
	 * Array (
	 *      [current] => Array (
	 *              [indecision] => 100
	 *              [reverse_bear] => 52
	 *              [reverse_bear_total] => 52
	 *              [reverse_bull] => 51
	 *              [reverse_bull_total] => 51
	 *      )
	 *      [recent] => Array (
	 *              [indecision] => 100
	 *              [reverse_bear] => 68
	 *              [reverse_bear_total] => 170
	 *              [reverse_bull] => 71
	 *              [reverse_bull_total] => 239
	 *              [continue_bull] => 58
	 *              [continue_bull_total] => 219
	 *              [continue_bear] => 53
	 *              [continue_bear_total] => 208
	 *       )
	 * )
	 * Which would indicate we were coming from a place that should have reversed a bull run
	 * and are not in a period of indecision.
	 * in this case, we would not enter a trade.
	 *
	 */
	public function candle_value($data) {
		if (empty($data)) {
			return ['err' => 'no data'];
		}
		$candles = new Candles();
		$candle_data = $candles->allCandles('BTC/USD', $data); // pair only mattters if data empty
		$ret['indecision'] = 0;

		$price_reversal_bear_keys = array_keys($this->price_reversal['bear']);
		$price_reversal_bull_keys = array_keys($this->price_reversal['bull']);
		$price_continuation_bear_keys = array_keys($this->price_continuation['bear']);
		$price_continuation_bull_keys = array_keys($this->price_continuation['bull']);
		$counter_purpose_bear_keys = array_keys($this->counter_purpose['bear']);
		$counter_purpose_bull_keys = array_keys($this->counter_purpose['bull']);

		if(isset($candle_data['current'])) {
			foreach ($candle_data['current'] as $key => $data) {
				/** current indecision is bad */
				if (in_array($key, $this->indecision)) {
					$ret['indecision'] = $ret['indecision'] + 100;
				}
				/** price reversal */
				if (in_array($key, $price_reversal_bear_keys)) {
					$ret['reverse_bear'] = $ret['reverse_bear'] ?? 0;
					$ret['reverse_bear'] = ($this->price_reversal['bear'][$key] > $ret['reverse_bear'] ? $this->price_reversal['bear'][$key] : $ret['reverse_bear']);
					$ret['reverse_bear_total'] = (@$ret['reverse_bear_total'] + $this->price_reversal['bear'][$key]); // ?? $this->price_reversal['bear'][$key]);
				}
				if (in_array($key, $price_reversal_bull_keys)) {
					$ret['reverse_bull'] = $ret['reverse_bull'] ?? 0;
					$ret['reverse_bull'] = ($this->price_reversal['bull'][$key] > $ret['reverse_bull'] ? $this->price_reversal['bull'][$key] : $ret['reverse_bull']);
					$ret['reverse_bull_total'] = (@$ret['reverse_bull_total'] + $this->price_reversal['bull'][$key]); // ?? $this->price_reversal['bull'][$key]);
				}

				/** price continuation */
				if (in_array($key, $price_continuation_bull_keys) || in_array($key, $counter_purpose_bear_keys)) {
					$candle_set = in_array($key, $price_continuation_bull_keys) ? $this->price_continuation['bull'] : $this->counter_purpose['bear'];
					$ret['continue_bull'] = $ret['continue_bull'] ?? 0;
					$ret['continue_bull'] = ($candle_set[$key] > $ret['continue_bull'] ? $candle_set[$key] : $ret['continue_bull']);
					$ret['continue_bull_total'] = (@$ret['continue_bull_total'] + $candle_set[$key]); // ?? $candle_set[$key]);
				}
				if (in_array($key, $price_continuation_bear_keys) || in_array($key, $counter_purpose_bull_keys)) {
					$candle_set = in_array($key, $price_continuation_bear_keys) ? $this->price_continuation['bear'] : $this->counter_purpose['bull'];
					$ret['continue_bear'] = $ret['continue_bear'] ?? 0;
					$ret['continue_bear'] = ($candle_set[$key] > $ret['continue_bear'] ? $candle_set[$key] : $ret['continue_bear']);
					$ret['continue_bear_total'] = (@$ret['continue_bear_total'] + $candle_set[$key]); // ?? $this->price_continuation['bear'][$key]);
				}
			}
			$return['current'] = $ret;
			$ret = [];
			$ret['indecision'] = 0;
		}
		if(isset($candle_data['recently'])) {
			foreach ($candle_data['recently'] as $key => $data) {
				/** recent indecision is okay, if we are done with it */
				if (in_array($key, $this->indecision)) {
					$ret['indecision'] = $ret['indecision'] + 100;
				}
				/** price reversal */
				if (in_array($key, $price_reversal_bear_keys)) {
					$ret['reverse_bear'] = $ret['reverse_bear'] ?? 0;
					$ret['reverse_bear'] = ($this->price_reversal['bear'][$key] > $ret['reverse_bear'] ? $this->price_reversal['bear'][$key] : $ret['reverse_bear']);
					$ret['reverse_bear_total'] = (@$ret['reverse_bear_total'] + $this->price_reversal['bear'][$key]); // ?? $this->price_reversal['bear'][$key]);
				}
				if (in_array($key, $price_reversal_bull_keys)) {
					$ret['reverse_bull'] = $ret['reverse_bull'] ?? 0;
					$ret['reverse_bull'] = ($this->price_reversal['bull'][$key] > $ret['reverse_bull'] ? $this->price_reversal['bull'][$key] : $ret['reverse_bull']);
					$ret['reverse_bull_total'] = (@$ret['reverse_bull_total'] + $this->price_reversal['bull'][$key]); // ?? $this->price_reversal['bull'][$key]);
				}

				/** price continuation */
				if (in_array($key, $price_continuation_bull_keys) || in_array($key, $counter_purpose_bear_keys)) {
					$candle_set = in_array($key, $price_continuation_bull_keys) ? $this->price_continuation['bull'] : $this->counter_purpose['bear'];
					$ret['continue_bull'] = $ret['continue_bull'] ?? 0;
					$ret['continue_bull'] = ($candle_set[$key] > $ret['continue_bull'] ? $candle_set[$key] : $ret['continue_bull']);
					$ret['continue_bull_total'] = (@$ret['continue_bull_total'] + $candle_set[$key]); // ?? $this->price_continuation['bull'][$key]);
				}
				if (in_array($key, $price_continuation_bear_keys) || in_array($key, $counter_purpose_bull_keys)) {
					$candle_set = in_array($key, $price_continuation_bear_keys) ? $this->price_continuation['bear'] : $this->counter_purpose['bull'];
					$ret['continue_bear'] = $ret['continue_bear'] ?? 0;
					$ret['continue_bear'] = ($candle_set[$key] > $ret['continue_bear'] ? $candle_set[$key] : $ret['continue_bear']);
					$ret['continue_bear_total'] = (@$ret['continue_bear_total'] + $candle_set[$key]); // ?? $this->price_continuation['bear'][$key]);
				}
			}
		}
		$return['recent'] = $ret;
		return $return;
	}

	static function get_candle_strengths($candles, $candle_set = 'recent') {
		$short = $long = 0;

		if(isset($candles[$candle_set])) {
			foreach ($candles[$candle_set] as $candle_category => $value) {
				switch ($candle_category) {
					case 'indecision':
						$short -= $value;
						$long -= $value;
//						return ['short' => -1, 'long' => -1];
						break;
					case 'continue_bear_total':
					case 'reverse_bear_total':
						$short += $value;
//						$long -= $value;
						break;
					case 'continue_bull_total':
					case 'reverse_bull_total':
						$long += $value;
//						$short -= $value;
						break;
				}
			}
		}
//		if(isset($candles['current'])) {
//			foreach($candles['current'] as $c=>$v) {
//				if($v > 0) {
//					$long++;
//				}
//				if($v < 0) {
//					$short++;
//				}
//				if(in_array($c, [
//		'gravestonedoji'
//		, 'longleggeddoji'
//		, 'highwave'
//		, 'rickshawman'
//		, 'shortline'
//		, 'spinningtop'
//	])) {
//					return ['short' => -1, 'long'=>-1];
//				}
//			}
//		}
//		return ['short' => $short, 'long'=>$long];
		return ['short' => self::get_candle_strength_from_score($short), 'long' => self::get_candle_strength_from_score($long)];
	}

	static function get_candle_strength_from_score($score) {
		return $score > 60 ? 1 : 0; // dropping support for multiple candle strengths,  for now... as candle strength didn't seem to make much odds!.. I will do more thorough candle comparisons at some point to work out a better strategy for this.

/*		if ($score > 400) {
			return 7;
		} else if ($score > 360) {
			return 6;
		} else if ($score > 300) {
			return 5;
		} else if ($score > 240) {
			return 4;
		} else if ($score > 180) {
			return 3;
		} else if ($score > 120) {
			return 2;
		} else if ($score > 60) {
			return 1;
		}
		return 0;*/
	}

}
