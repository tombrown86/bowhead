<?php
/**
 * Created by PhpStorm.
 * User: joeldg
 * Date: 6/25/17
 * Time: 1:46 PM
 */

namespace Bowhead\Traits;

use Bowhead\Util\Indicators;
use Illuminate\Support\Facades\DB;

/**
 * Class Strategies
 * @package Bowhead\Traits
 */
trait Strategies
{
    protected $strategies_all = [
         'bowhead_sar_stoch'
        ,'bowhead_awesome_macd'
        ,'bowhead_adx_smas'
        ,'bowhead_rsi_macd'
        ,'bowhead_sar_rsi'
        ,'bowhead_stoch_adx'
        ,'bowhead_cci_scalper'
        ,'bowhead_ema_scalper'
        ,'bowhead_ema_stoch_rsi'
        ,'bowhead_double_volatility'
        ,'bowhead_adx_momentum'
        ,'bowhead_base_150'
        ,'bowhead_breakout_ma'
        ,'bowhead_sar_awesome'
        ,'bowhead_cci_ema'
        ,'bowhead_bband_rsi'
        ,'bowhead_ema_adx_macd'
        ,'bowhead_mov_avg_sar'
        ,'bowhead_momentum'
        ,'bowhead_sma_stoch_rsi'

        // not organized into time series yet...
        // need to test them more..
        ,'bowhead_sar_sma'
        ,'bowhead_ema_adx'
        ,'bowhead_trend_bounce'
        ,'bowhead_5th_element'
        ,'bowhead_powerranger'
        ,'bowhead_famamama'
    ];

    protected $strategies_1m = [
         'bowhead_sar_stoch'
        ,'bowhead_awesome_macd'
        ,'bowhead_adx_smas'
        ,'bowhead_rsi_macd'
        ,'bowhead_sar_rsi'
        ,'bowhead_ema_scalper'
    ];

    protected $strategies_5m = [
         'bowhead_stoch_adx'
        ,'bowhead_cci_scalper'
        ,'bowhead_adx_momentum'
    ];

    protected $strategies_15m = [
         'bowhead_double_volatility'
        ,'bowhead_bband_rsi'
    ];

    protected $strategies_30m = ['bowhead_sar_awesome'];

    protected $strategies_1h = [
         'bowhead_ema_stoch_rsi'
        ,'bowhead_base_150'
        ,'bowhead_breakout_ma' // technically this is a 1d, but can be used more
        ,'bowhead_cci_ema'
        ,'bowhead_ema_adx_macd' // technically a 4h
        ,'bowhead_mov_avg_sar'
        ,'bowhead_momentum'
        ,'bowhead_sma_stoch_rsi'
    ];

    /**
     * @param $data
     * @param $period
     *
     * @return mixed
     *
     *  util function, lots of custom EMA's here.
     */
    private function ema_maker($data, $period, $prior=false)
    {
        $emaArray = trader_ema($data, $period);
        $ema = @array_pop($emaArray) ?? 0;
        $ema_prior = @array_pop($emaArray) ?? 0;
        return ($prior ? $ema_prior : $ema);
    }

    /**
     * @param      $data
     * @param      $period
     * @param bool $prior
     *
     * @return mixed
     */
    private function sma_maker($data, $period, $prior=false)
    {
        $smaArray = trader_sma($data, $period);
        $sma = @array_pop($smaArray) ?? 0;
        $sma_prior = @array_pop($smaArray) ?? 0;
        return ($prior ? $sma_prior : $sma);
    }

    /**
     * @param      $data
     * @param      $period
     * @param bool $prior
     *
     * @return mixed
     */
    private function ma_maker($data, $period, $prior=false)
    {
        $maArray = trader_ma($data, $period);
        $ma = @array_pop($maArray) ?? 0;
        $ma_prior = @array_pop($maArray) ?? 0;
        return ($prior ? $ma_prior : $ma);
    }

    /**
     *  TODO
     *  Predictive moving average
     *  when trigger crosses
     */
    private function pma()
    {
        /**
        price((h+l)/2)
        Vars: WMAl(0), WMA2(01,Predict ( 0 , Trigger (0);
        WMAl = (7*Price + 6*Price[l] + 5*Price[2] + 4*Price[3] + 3*Price[4] + 2*Price[S] + Price[6]) / 28;
        WMA2 = (7*WMA1 + 6*WMA1[l] + 5*WMA1[21 + 4*WMA1[3] + 3*WMA1[41 + 2*WMA1[51 + WMA1[6]) / 28;

        Predict = 2*WMA1 - WMA2
        Trigger = (4*Predict + 3* Predict[1] + 2*Predict[2] + Predict) / 10
        //*/
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_sar_stoch($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $fsar   = $indicators->fsar($pair, $data); // custom sar for forex
        $stoch  = $indicators->stoch($pair, $data);
        $stochf = $indicators->stochf($pair, $data);
        $stochs = (($stoch == -1 || $stochf == -1) ? -1 : ( ($stoch == 1 || $stochf == 1) ? 1 : 0) );
        if ($fsar == -1 && ($stoch == -1 || $stochf == -1)) {
            $return['side']     = 'short';
            $return['strategy'] = 'sar_stoch';
            return ($return_full ? $return : -1);
        } elseif ($fsar == 1 && ($stoch == 1 || $stochf == 1)) {
            $return['side']     = 'long';
            $return['strategy'] = 'sar_stoch';
            return ($return_full ? $return : 1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_awesome_macd($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();
        $ao     = $indicators->awesome_oscillator($pair, $data);
        $macd   = $indicators->macd($pair, $data);
        /** Awesome + MACD */
        if ($macd < 0 && $ao < 0) {
            $return['side']     = 'short';
            $return['strategy'] = 'awesome_macd';
            return ($return_full ? $return : -1);
        }
        if ($macd > 0 && $ao > 0) {
            $return['side']     = 'long';
            $return['strategy'] = 'awesome_macd';
            return ($return_full ? $return : 1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_adx_smas($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $adx         = $indicators->adx($pair, $data);

        $_sma6       = trader_sma($data['close'], 6);
        $sma6        = array_pop($_sma6);
        $prior_sma6  = array_pop($_sma6);

        $_sma40      = trader_sma($data['close'], 40);
        $sma40       = array_pop($_sma40);
        $prior_sma40 = array_pop($_sma40);
        /** have the lines crossed? */
        // https://www.tradingview.com/x/kH5sdnHR/
        $sixCross   = (($prior_sma6 < $sma40 && $sma6 > $sma40) ? 1 : 0);
        $fortyCross = (($prior_sma40 < $sma6 && $sma40 > $sma6) ? 1 : 0);

        if ($adx > 0 && $sixCross == 1) {
            $return['side']     = 'short';
            $return['strategy'] = 'adx_smas';
            return ($return_full ? $return : -1);
        }
        if ($adx > 0 && $fortyCross == 1) {
            $return['side']     = 'long';
            $return['strategy'] = 'adx_smas';
            return ($return_full ? $return : 1);
        }
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_rsi_macd($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $rsi = $indicators->rsi($pair, $data, 14); // 19 more accurate?
        /** custom macd - not using bowhead indicator macd*/
        $macd = trader_macd($data['close'], 24, 52, 18);
        $macd_raw = $macd[0];
        $signal   = $macd[1];
        $hist     = $macd[2];
        $macd = (array_pop($macd_raw) - array_pop($signal));

        /** rsi + macd */
        if ($macd > 0 && $rsi > 0) {
            $return['side']     = 'long';
            $return['strategy'] = 'rsi_macd';
            return ($return_full ? $return : 1);
        }
        if ($macd < 0 && $rsi < 0) {
            $return['side']     = 'short';
            $return['strategy'] = 'rsi_macd';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_sar_rsi($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $fsar = $indicators->fsar($pair, $data); // custom sar for forex
        $rsi  = $indicators->rsi($pair, $data, 14); // 19 more accurate?

        if ($fsar == -1 && $rsi < 0) {
            $return['side']     = 'short';
            $return['strategy'] = 'sar_rsi';
            return ($return_full ? $return : -1);
        } elseif ($fsar == 1 && $rsi > 0) {
            $return['side']     = 'long';
            $return['strategy'] = 'sar_rsi';
            return ($return_full ? $return : 1);
        }
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *      stoch 5,3,3
     *      adx(14)
     *
     *      short-term indicator.
     *      typically close these after a 15pt rise/fall
     *
     * @return int
     */
    public function bowhead_stoch_adx($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $recentData2 = $data['close'];
        $curr  = array_pop($recentData2);
        $prev  = array_pop($recentData2);
        $prior = array_pop($recentData2);
        $bullish = $bearish = false;
        if ($curr > $prev && $prev > $prior) {
            $bullish = true; // last two candles were bullish
        }
        if ($curr < $prev && $prev < $prior) {
            $bearish = true; // last two candles were bearish
        }
        $adx   = $indicators->adx($pair, $data);
        $stoch = $indicators->stoch($pair, $data);

        if ($adx == -1 && $stoch < 0 && $bearish) {
            $return['side']     = 'short';
            $return['strategy'] = 'stoch_adx';
            return ($return_full ? $return : -1);
        } elseif ($adx == 1 && $stoch > 0 && $bullish) {
            $return['side']     = 'long';
            $return['strategy'] = 'stoch_adx';
            return ($return_full ? $return : 1);
        }
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return array|int
     *
     *         take profit when the 10 EMA and 21 EMA cross in the
     *         opposite direction
     */
    public function bowhead_cci_scalper($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        // 128 is our default period, so we need to verify it is bigger
        if (count($data['close']) < 200) {
            return array('err' => "need larger data set. (200 min)");
        }

        $cci = trader_cci($data['high'], $data['low'], $data['close'], 200);
        $cci = array_pop($cci);

        $ema10 = $this->ema_maker($data['close'], 10);
        $ema21 = $this->ema_maker($data['close'], 21);
        $ema50 = $this->ema_maker($data['close'], 50);

        if ($cci > 0 && $ema10 > $ema21 && $ema10 > $ema50) {
            $return['side']     = 'long';
            $return['strategy'] = 'cci_scalper';
            return ($return_full ? $return : 1);
        }
        if ($cci < 0 && $ema10 < $ema21 && $ema10 < $ema50) {
            $return['side']     = 'short';
            $return['strategy'] = 'cci_scalper';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_ema_scalper($pair, $data, $return_full=false)
    {
        $red = $redp = $blue = $bluep = $green = $greenp = [];
        /**
         *  You have probably seen these large masses of EMA's
         *  On graphs looking like spider webs, they are used like this.
         *
         *  We are most interested when they all cross each other, so
         *  I compute the averages of them and it gives us numbers we
         *  can use.
         */
        $e1 = [2,3,4,5,6,7,8,9,10,11,12,13,14,15];      // red
        #$e2 = [17,19,21,23,25,27,29,31,33,35,37,39,41]; // blue
        $e3 = [44,47,50,53,56,59,62,65,68,71,74];       // green
        foreach ($e1 as $e) {
            $red[] = $this->ema_maker($data['close'], $e);
            $redp[] = $this->ema_maker($data['close'], $e, 1); // prior
        }
        $red_avg = (array_sum($red)/count($red));
        $redp_avg = (array_sum($redp)/count($redp));

        /**
         *  We use the blue lines for after we already have open
         *  positions, we can add to positions if the price touches
         *  the blue line
         */
        #foreach ($e2 as $e) {
        #    $blue[] = $this->ema_maker($data['close'], $e);
        #}
        #$blue_avg = (array_sum($blue)/count($blue));

        foreach ($e3 as $e) {
            $green[] = $this->ema_maker($data['close'], $e);
        }
        $green_avg = (array_sum($green)/count($green));

        if ($red_avg < $green_avg && $redp_avg > $green_avg){
            $return['side']     = 'long';
            $return['strategy'] = 'ema_scalper';
            return ($return_full ? $return : 1);
        }
        if ($red_avg > $green_avg && $redp_avg < $green_avg){
            $return['side']     = 'short';
            $return['strategy'] = 'ema_scalper';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_ema_stoch_rsi($pair, $data, $return_full=false)
    {
        $ema5   = $this->ema_maker($data['close'], 5);
        $ema5p  = $this->ema_maker($data['close'], 5, 1);
        $ema10  = $this->ema_maker($data['close'], 10);
        #$ema10p = $this->ema_maker($data['close'], 10, 1);

        $stoch = trader_stoch($data['high'], $data['low'], $data['close'], 14, 3, TRADER_MA_TYPE_SMA, 3, TRADER_MA_TYPE_SMA);
        $slowk = $stoch[0];
        $slowd = $stoch[1];

        $slowk1 = array_pop($slowk);
        $slowkp = array_pop($slowk);
        $slowd1 = array_pop($slowd);
        $slowdp = array_pop($slowd);

        $k_up = $d_up = false;
        if ($slowkp < $slowk1) {
            $k_up = true;
        }
        if ($slowdp < $slowd1) {
            $d_up = true;
        }
        $pointed_down = $pointed_up = false;
        if ($slowk < 80 && $slowd < 80 && $k_up && $d_up) {
            $pointed_up = true;
        }
        if ($slowk > 20 && $slowd > 20 && !$k_up && !$d_up) {
            $pointed_down = true;
        }

        $rsi = trader_rsi ($data['close'], 14);
        $rsi = array_pop($rsi);

        if ($ema5 >= $ema10 && $ema5p < $ema10 && $rsi > 50 && $pointed_up) {
            $return['side']     = 'long';
            $return['strategy'] = 'ema_stoch_rsi';
            return ($return_full ? $return : 1);
        }
        if ($ema5 <= $ema10 && $ema5p > $ema10 && $rsi < 50 && $pointed_down) {
            $return['side']     = 'short';
            $return['strategy'] = 'ema_stoch_rsi';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_double_volatility($pair, $data, $return_full=false)
    {
        $rsi = trader_rsi ($data['close'], 11);
        $rsi = array_pop($rsi);

        $sma20_high = $this->sma_maker($data['high'], 20);
        $sma20_low  = $this->sma_maker($data['low'], 20);
        $sma5_high  = $this->sma_maker($data['high'], 5);
        #$sma5_low   = $this->sma_maker($data['low'], 5);

        if ($sma5_high > $sma20_high && $rsi > 65) {
            $return['side']     = 'long';
            $return['strategy'] = 'double_volatility';
            return ($return_full ? $return : 1);
        }
        if ($sma5_high < $sma20_low && $rsi < 35) {
            $return['side']     = 'short';
            $return['strategy'] = 'double_volatility';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_adx_momentum($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $adx  = trader_adx($data['high'], $data['low'], $data['close'], 25);
        $adx  = array_pop($adx);
        $mom  = trader_mom($data['close'], 14);
        $mom  = array_pop($mom);
        $fsar = $indicators->fsar($pair, $data);

        if ($adx > 25 && $mom > 100 && $fsar > 0) {
            $return['side']     = 'long';
            $return['strategy'] = 'adx_momentum';
            return ($return_full ? $return : 1);
        }
        if ($adx > 25 && $mom < 100 && $fsar < 0) {
            $return['side']     = 'short';
            $return['strategy'] = 'adx_momentum';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return array|int
     */
    public function bowhead_base_150($pair, $data, $return_full=false)
    {
        if (count($data['close']) < 365) {
            return 0;
            return array('err' => "need larger data set. (365 min)");
        }
        $ma6   = $this->ma_maker($data['close'], 6);
        $ma6p  = $this->ma_maker($data['close'], 6, 1);
        $ma35  = $this->ma_maker($data['close'], 35);
        $ma35p = $this->ma_maker($data['close'], 35, 1);

        $ma150 = $this->ma_maker($data['close'], 150);
        $ma365 = $this->ma_maker($data['close'], 365);

        if (   $ma6 > $ma150
            && $ma6 > $ma365
            && $ma35 > $ma150
            && $ma35 > $ma365
            && $ma6p < $ma150
            && $ma6p < $ma365
            && $ma35p < $ma150
            && $ma35p < $ma365) {
            $return['side']     = 'long';
            $return['strategy'] = 'base_150';
            return ($return_full ? $return : 1);
        }
        if (   $ma6 < $ma150
            && $ma6 < $ma365
            && $ma35 < $ma150
            && $ma35 < $ma365
            && $ma6p > $ma150
            && $ma6p > $ma365
            && $ma35p > $ma150
            && $ma35p > $ma365) {
            $return['side']     = 'short';
            $return['strategy'] = 'base_150';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_breakout_ma($pair, $data, $return_full=false)
    {
        $sma20_low  = $this->sma_maker($data['low'], 20);
        $ema34      = $this->ema_maker($data['close'], 34);
        $adx  = trader_adx($data['high'], $data['low'], $data['close'], 13);
        $adx  = array_pop($adx);

        if ($ema34 > $sma20_low && $adx > 25) {
            $return['side']     = 'long';
            $return['strategy'] = 'breakout_ma';
            return ($return_full ? $return : 1);
        }
        if ($ema34 < $sma20_low && $adx > 25) {
            $return['side']     = 'short';
            $return['strategy'] = 'breakout_ma';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_sar_awesome($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $sar  = $indicators->sar($pair, $data);
        $ema5 = $this->ema_maker($data['close'], 5);
        $ao   = $indicators->awesome_oscillator($pair, $data);
        $price = array_pop($data['close']);

        if ($sar < 0 && $ao > 0 && $ema5 < $price) {
            $return['side']     = 'long';
            $return['strategy'] = 'sar_awesome';
            return ($return_full ? $return : 1);
        }
        if ($sar > 0 && $ao < 0 && $ema5 > $price) {
            $return['side']     = 'short';
            $return['strategy'] = 'sar_awesome';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_cci_ema($pair, $data, $return_full=false)
    {
        $ema8   = $this->ema_maker($data['close'], 8);
        $ema8p  = $this->ema_maker($data['close'], 8, 1);
        $ema28  = $this->ema_maker($data['close'], 28);
        $cci    = trader_cci($data['high'], $data['low'], $data['close'], 30);
        $cci    = array_pop($cci);

        if($ema8 > $ema28 && $ema8p < $ema28 && $cci > 0){
            $return['side']     = 'long';
            $return['strategy'] = 'cci_ema';
            return ($return_full ? $return : 1);
        }
        if ($ema8 < $ema28 && $ema8p > $ema28 && $cci < 0){
            $return['side']     = 'short';
            $return['strategy'] = 'cci_ema';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_bband_rsi($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $rsi    = $indicators->rsi($pair, $data, 11);
        $bbands = $indicators->bollingerBands($pair, $data, 20);

        if ($rsi > -1 && $bbands == -1) {
            $return['side']     = 'long';
            $return['strategy'] = 'bband_rsi';
            return ($return_full ? $return : 1);
        }
        if ($rsi < 1 && $bbands == 1) {
            $return['side']     = 'short';
            $return['strategy'] = 'bband_rsi';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_ema_adx_macd($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $ema4  = $this->ema_maker($data['close'], 4);
        $ema4p = $this->ema_maker($data['close'], 4, 1);

        $ema10 = $this->ema_maker($data['close'], 10);
        $adx  = trader_adx($data['high'], $data['low'], $data['close'], 28);
        $adx  = array_pop($adx);

        $macd = $indicators->macd($pair, $data, 5, 10, 4);

        if ($ema4 < $ema10 && $ema4p > $ema10 && $macd < 0){
            $return['side']     = 'long';
            $return['strategy'] = 'ema_adx_macd';
            return ($return_full ? $return : 1);
        }
        if ($ema4 > $ema10 && $ema4p < $ema10 && $macd > 0){
            $return['side']     = 'short';
            $return['strategy'] = 'ema_adx_macd';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_mov_avg_sar($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $sar  = $indicators->sar($pair, $data);

        $ema10   = $this->ema_maker($data['close'], 10);
        $ema10p  = $this->ema_maker($data['close'], 10, 1);
        $ema25   = $this->ema_maker($data['close'], 25);
        $ema50   = $this->ema_maker($data['close'], 50);

        if ($ema10 > $ema25 && $ema10 > $ema50 && $ema10p < $ema25 && $ema10p < $ema50 && $sar > 0) {
            $return['side']     = 'long';
            $return['strategy'] = 'mov_avg_sar';
            return ($return_full ? $return : 1);
        }
        if ($ema10 < $ema25 && $ema10 < $ema50 && $ema10p > $ema25 && $ema10p > $ema50 && $sar < 0) {
            $return['side']     = 'short';
            $return['strategy'] = 'mov_avg_sar';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_momentum($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $price  = array_pop($data['close']);
        $sma21  = $this->sma_maker($data['close'], 21);
        $sma21p = $this->sma_maker($data['close'], 21, 1);
        $sma11  = $this->sma_maker($data['close'], 11);
        $mom    = trader_mom($data['close'], 30);
        $mom    = array_pop($mom);
        $rsi    = $indicators->rsi($pair, $data, 14);

        if ($rsi > 0 && $mom > 100 && $sma11 > $sma21 && $price > $sma21 && $price > $sma11) {
            $return['side']     = 'long';
            $return['strategy'] = 'momentum';
            return ($return_full ? $return : 1);
        }
        if ($rsi < 0 && $mom > 100 && $sma11 < $sma21 && $price < $sma21 && $price < $sma11) {
            $return['side']     = 'short';
            $return['strategy'] = 'momentum';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * @return int
     */
    public function bowhead_sma_stoch_rsi($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();

        $price  = array_pop($data['close']);
        $sma150  = $this->sma_maker($data['close'], 150);
        $stoch = trader_stoch($data['high'], $data['low'], $data['close'], 8, 3, TRADER_MA_TYPE_SMA, 3, TRADER_MA_TYPE_SMA);
        $slowk = $stoch[0];
        $slowd = $stoch[1];

        $slowk = array_pop($slowk);
        $slowd = array_pop($slowd);

        $rsi = trader_rsi ($data['close'], 3);
        $rsi = array_pop($rsi);

        if ($price > $sma150 && $rsi < 20 && $slowk > 70 && $slowk > $slowd) {
            $return['side']     = 'long';
            $return['strategy'] = 'sma_stoch_rsi';
            return ($return_full ? $return : 1);
        }
        if ($price < $sma150 && $rsi > 80 && $slowk > 70 && $slowk < $slowd) {
            $return['side']     = 'short';
            $return['strategy'] = 'sma_stoch_rsi';
            return ($return_full ? $return : -1);
        }
        return 0;
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     *  1min-scalp
     *  sar + sma60
     *  long=price>sma60, sar below price (sl 15 below, tp 10 above)
     *  short=price<sma60, sar above price (sl 15, tp 10)
     *
     *  @return int
     */
    public function bowhead_sar_sma($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();
        $price      = array_pop($data['close']);
        $sar        = $indicators->fsar($pair, $data);
        $sma60      = $this->sma_maker($data['close'], 60);

        if ($price > $sma60 && $sar == 1) {
            $return['side']     = 'long';
            $return['strategy'] = 'sar_sma';
            return ($return_full ? $return : 1);
        } elseif ($price < $sma60 && $sar == -1) {
            $return['side']     = 'short';
            $return['strategy'] = 'sar_sma';
            return ($return_full ? $return : -1);
        } else {
            return 0;
        }
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     *  1h-day
     *  ema 12
     *  ema 36
     *  ad x14
     *  long ema12 > ema36 (from below) and adx
     *  short ema12 < ema36 (from above) and adx
     *
     *  @return int
     */
    public function bowhead_ema_adx($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();
        $ema12  = $this->ema_maker($data['close'], 12);
        $ema12p = $this->ema_maker($data['close'], 12, 1); // prior
        $ema36  = $this->ema_maker($data['close'], 36);
        $adx    = $indicators->adx($pair, $data);

        if ($ema12 > $ema36 && $ema12p < $ema36 && $adx) {
            $return['side'] = 'long';
            $return['strategy'] = 'ema_adx';
            return ($return_full ? $return : 1);
        } elseif ($ema12 < $ema36 && $ema12p > $ema36 && $adx) {
            $return['side']     = 'short';
            $return['strategy'] = 'ema_adx';
            return ($return_full ? $return : -1);
        } else {
            return 0;
        }
    }


    /**
     *  1. Bollinger Bands (12, deviation [Dev] 2)
     *  2. Bollinger Bands (12, Dev 4)
     *
     *  1h - swing
     *  long= price hits #1 upper band and retraces back to center line
     *  short= price hits #1 lower band and retraces back to center line
     *  SL and TP are #2 lines
     *
     *  NOTE: this is really just a simple bbands retrace strategy.
     *
     *  @return int
     */
    public function bowhead_trend_bounce($pair, $data, $return_full=false)
    {
        // custom bbands
        $bbands1 = trader_bbands($data['close'], 12, 2, 2, 0);
        $upper1  = $bbands1[0];
        $middle1 = $bbands1[1];
        $lower1  = $bbands1[2];

        $bbands2 = trader_bbands($data['close'], 12, 4, 4, 0);
        $upper2  = $bbands2[0];
        $middle2 = $bbands2[1];
        $lower2  = $bbands2[2];

        $price = array_pop($data['close']);
        $high  = array_pop($data['high']);
        $low   = array_pop($data['low']);

        if ($high >= $upper1 && ($price <= $middle1 || $low <= $middle1)){
            $return['side'] = 'long';
            $return['strategy'] = 'trend_bounce';
            return ($return_full ? $return : 1);
        } elseif ($high >= $upper1 && ($price <= $middle1 || $low <= $middle1)){
            $return['side'] = 'short';
            $return['strategy'] = 'trend_bounce';
            return ($return_full ? $return : -11);
        } else {
            return 0;
        }
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     *  MACD (data, 12, 26, 9)
     *  long = macd + four positive bars on histo
     *  short = macd + four negative bars on histo
     *
     *  NOTE: This is a pretty simple 'only' MACD strategy.
     *
     *  @return int
     */
    public function bowhead_5th_element($pair, $data, $return_full=false)
    {
        $indicators = new Indicators();
        $macd_base = $indicators->macd($pair, $data);

        // custom macd
        $macd = trader_macd($data['close'], 12, 26, 9);
        $macd_raw = $macd[0];
        $signal   = $macd[1];
        $hist     = $macd[2];

        $h1 = array_pop($hist);
        $h2 = array_pop($hist);
        $h3 = array_pop($hist);
        $h4 = array_pop($hist);
        $h5 = array_pop($hist);

        if ($macd_base && ($h1 > $h2 && $h2 > $h3 && $h3 > $h4 && $h4 > $h5)) {
            $return['side'] = 'long';
            $return['strategy'] = '5th_element';
            return ($return_full ? $return : 1);
        } elseif ($macd_base && ($h1 < $h2 && $h2 < $h3 && $h3 < $h4 && $h4 < $h5)) {
            $return['side'] = 'short';
            $return['strategy'] = '5th_element';
            return ($return_full ? $return : -1);
        } else {
            return 0;
        }

    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     * 1h range
     * stoch
     *      %K period = 10
     *      %D period = 3
     *      Slowing = 3
     *      Price field = High/Low
     *      MA method = Simple
     *      Levels 20 and 80
     *  determine support and resistance range
     *
     *  long=stoch crosses over 20
     *  short stoch crosses below 80
     *
     *  Stoch only for ranging/sideways markets only
     *
     *  @return int
     */
    public function bowhead_powerranger($pair, $data, $return_full=false)
    {
        $stoch = trader_stoch($data['high'], $data['low'], $data['close'], 10, 3, TRADER_MA_TYPE_SMA, 3, TRADER_MA_TYPE_SMA);
        $slowk = $stoch[0];
        $slowd = $stoch[1];

        $slowka = array_pop($slowk);
        $slowkp = array_pop($slowk);

        $slowda = array_pop($slowd);
        $slowdp = array_pop($slowd);

        if ($slowka > 20 && $slowkp < 20 || $slowda > 20 && $slowdp < 20) {
            $return['side'] = 'long';
            $return['strategy'] = 'powerranger';
            return 1;
        }elseif ($slowka < 80 && $slowkp > 80 || $slowda < 80 && $slowdp > 80) {
            $return['side'] = 'short';
            $return['strategy'] = 'powerranger';
            return -1;
        } else {
            return 0;
        }
    }

    /**
     * @param      $pair
     * @param      $data
     * @param bool $return_full
     *
     *      Rocket Science for Traders (page 182)
     *
     *      mama = trader_mama ( array $real, 0.5, 0.05)
     *      fama = trader_mama ( array $real, 0.25, 0.025) // fast mama
     *
     *      sell = fama crosses from below to above mama
     *      buy  = fama crosses from above to below mama
     *
     *  @return int
     */
    public function bowhead_famamama($pair, $data, $return_full=false)
    {
        // trader_mama ( array $real [, float $fastLimit [, float $slowLimit ]] )
        $mama = trader_mama ($data['close'], 0.5, 0.05);
        $fama = trader_mama ($data['close'], 0.25, 0.025);

        $mama_current = array_pop($mama);
        $fama_current = array_pop($fama);
        $fama_prior   = array_pop($fama);

        if ($fama_current > $mama_current && $fama_prior < $mama_current) {
            $return['side'] = 'long';
            $return['strategy'] = 'famamama';
            return 1;
        } elseif($fama_current < $mama_current && $fama_prior > $mama_current) {
            $return['side'] = 'short';
            $return['strategy'] = 'famamama';
            return -1;
        } else {
            return 0;
        }
    }


	private $special_combos = [
		'1m' => [
                        'both' => [['indicators' => ['macd', 'stoch']],
                                ['indicators' => ['macd', 'stochf']],
                                ['indicators' => ['macd', 'stochf']],
                                ['indicators' => ['macd', 'willr']],
                                ['indicators' => ['macd', 'cci']],
                                ['indicators' => ['ao', 'atr']],
                                ['indicators' => ['ao', 'er']],
                                ['indicators' => ['macd', 'rsi']]],
                        'long' => [
                                ['indicators' => ['macd', 'rsi']],
                                ['indicators' => ['ao', 'adx']],
                                ['indicators' => ['macd', 'adx']],
                                ['indicators' => ['macd', 'bollingerBands']],
                                ['indicators' => ['ao', 'hli']],
                                ['indicators' => ['macd', 'er']]]
                        ]
		, '5m' => [
                        'both' => [['indicators' => ['macd', 'stoch']],
                                ['indicators' => ['macd', 'stochf']],
                                ['indicators' => ['macd', 'stochf']],
                                ['indicators' => ['macd', 'willr']],
                                ['indicators' => ['macd', 'cci']],
                                ['indicators' => ['ao', 'atr']],
                                ['indicators' => ['ao', 'er']],
                                ['indicators' => ['macd', 'rsi']]],
                        'long' => [
                                ['indicators' => ['macd', 'rsi']],
                                ['indicators' => ['ao', 'adx']],
                                ['indicators' => ['macd', 'adx']],
                                ['indicators' => ['macd', 'bollingerBands']],
                                ['indicators' => ['ao', 'hli']],
                                ['indicators' => ['macd', 'er']]]
                        ]
	];

	function check_for_special_combo($pair, $indicators, $candles, $interval='1m') {
		// find any candle first
		$long_candle = $short_candle = FALSE;
		if (isset($candles['current'])) {
			foreach ($candles['current'] as $candle_value) {
				if ($candle_value > 0) {
					$long_candle = TRUE;
				} else if ($candle_value < 0) {
					$short_candle = TRUE;
				}
			}
		}
		if ($long_candle || $short_candle) {
			$long_matches = $short_matches = [];
			foreach ($this->special_combos[$interval] as $longorshort => $technique_list) {

				foreach ($technique_list as $technique) {
					$all_signal_long = $longorshort == 'both' || $longorshort == 'long';
					$all_signal_short = $longorshort == 'both' || $longorshort == 'short';
					foreach ($technique['indicators'] as $indicator) {
						$all_signal_long = $indicators[$indicator] > 0 && $all_signal_long;
						$all_signal_short = $indicators[$indicator] < 0 && $all_signal_short;
					}

					if ($long_candle && $all_signal_long)
						$long_matches[] = print_r($technique['indicators'], 1);
					if ($short_candle && $all_signal_short)
						$short_matches[] = print_r($technique['indicators'], 1);
				}
			}

			if (count($long_matches))
				return ['signal' => 'long', 'long_matches' => $long_matches];
			if (count($short_matches))
				return ['signal' => 'short', 'short_matches' => $short_matches];
		}
		return ['signal' => 'none'];
	}

	/**
	 * From my testing, it seemed roughly 1-5 candle strengths measurements preceeded profitable treades,
	 * whilst higher strengths were not.. Since we may not have enough strat knowledge
	 * for each specific candle strength, this method will group each specific strength into low, medium, high ranges.
	 *
	function get_candle_strength_range($candle_strength) {
		switch($candle_strength) {
			case 1:
			case 2:
			case 3:
			case 4:
			case 5:
				return [1,2,3,4,5];
			case 6:
			case 7:
				return [6,7];
			default: 
				return [$candle_strength];
		}
	}*/

	function check_terry_knowledge($pair, $indicators, $candles, $interval='1m') {
		$candle_strengths = CandleMap::get_candle_strengths($candles);

		$long_indicators = $short_indicators = [];
		foreach($indicators as $indicator_name => $indicator_value) {
			if($indicator_value > 0) {
				$long_indicators[] = $indicator_name;
			}
			else if($indicator_value < 0) {
				$short_indicators[] = $indicator_name;
			}
		}

		foreach(['long', 'short'] as $los) {
			$indicators = ${$los.'_indicators'};
			echo $los . ' indicators: '.implode(',',array_values($indicators));
			echo '.. '.count($indicators).' '.$los.' indicators and '.$los.'  candle strength '.$candle_strengths[$los].'...';

			if(count($indicators) && $candle_strengths[$los] > 0) {
				sort($indicators);
				$doubles = [];
				$this->combinations($indicators, 2, $doubles);
				$triples = [];
				$this->combinations($indicators, 3, $triples);
				$quadruples = [];
				$this->combinations($indicators, 4, $quadruples);
				$indicator_combinations = array_merge($doubles, $triples, $quadruples);

				// get strategy names (indicator combs) for IN clause
				(count($indicators) > 4) && $indicator_combinations[] = $indicators; // add full list, for potential exact match
				$strategy_names = [];
				foreach($indicator_combinations as $combination) {
					sort($combination);
					$strategy_names[] = 'x_candles__and__'.implode('_', $combination);
				}

				DB::enableQueryLog();
				$knowledge = DB::table('terry_strategy_knowledge')
					->select(DB::raw('*'))
					->where('instrument', str_replace(['_', '-'], '/', $pair))
					->where('interval', $interval)
					->where('percentage_'.$los.'_win', '>=', 70) // successful strats only
					->where('test_confirmations', '>=', 5) // with enough confirmations to be a valuable stat
//					->where('percentage_'.$los.'_win', '>=', 0) // successful strats only
//					->where('test_confirmations', '>=', 1) // with enough confirmations to be a valuable stat
					->where('candle_strength', '>', 0)
					->whereIn('strategy_name', $strategy_names) 
//					->whereIn('candle_strength', $this->get_candle_strength_range($candle_strengths[$los]))
					->orderByRaw('indicator_count desc, avg_'.$los.'_profit desc '); //(candle_strength = '.(int)$candle_strengths[$los].') desc, 


				$knowledge = $knowledge->get();
//echo  print_r(DB::getQueryLog(), 1);
//				file_put_contents('/home/tom/results/wc_experiment_queries', print_r(DB::getQueryLog(), 1), FILE_APPEND);
				DB::disableQueryLog();

				if(count($knowledge)) {
					echo 'knowledge rows: ' ;
//					print_r( $knowledge);
					return ['signal' => $los, 'bounds_method'=>$knowledge[0]->bounds_strategy_name, 'knowledge_row'=>$knowledge[0]];
				}
			}
		}

		return ['signal' => 'none'];
	}

	/*use exact indicator match only*/
	function check_terry_knowledge2($pair, $indicators, $candles, $interval='1m') {
		$candle_strengths = CandleMap::get_candle_strengths($candles);

		$long_indicators = $short_indicators = [];
		foreach($indicators as $indicator_name => $indicator_value) {
			if($indicator_value > 0) {
				$long_indicators[] = $indicator_name;
			}
			else if($indicator_value < 0) {
				$short_indicators[] = $indicator_name;
			}
		}

		foreach(['long', 'short'] as $los) {
			$indicators = ${$los.'_indicators'};
			echo $los . ' indicators: '.implode(',',array_values($indicators));
			echo '.. '.count($indicators).' '.$los.' indicators and '.$los.'  candle strength '.$candle_strengths[$los].'...';

			if(count($indicators) && $candle_strengths[$los] > 0) {
				sort($indicators);
				$indicator_combinations = [];
				$indicator_combinations[] = $indicators; // add full list, for potential exact match
				$strategy_names = [];
				foreach($indicator_combinations as $combination) {
					sort($combination);
					$strategy_names[] = 'x_candles__and__'.implode('_', $combination);
				}

				DB::enableQueryLog();
				$knowledge = DB::table('terry_strategy_knowledge')
					->select(DB::raw('*'))
					->where('instrument', str_replace(['_', '-'], '/', $pair))
					->where('interval', $interval)
					->where('percentage_'.$los.'_win', '>=', 70) // successful strats only
					->where('test_confirmations', '>=', 5) // with enough confirmations to be a valuable stat
//					->where('percentage_'.$los.'_win', '>=', 0) // successful strats only
//					->where('test_confirmations', '>=', 1) // with enough confirmations to be a valuable stat
					->where('candle_strength', '>', 0)
					->whereIn('strategy_name', $strategy_names) 
//					->whereIn('candle_strength', $this->get_candle_strength_range($candle_strengths[$los]))
					->orderByRaw('indicator_count desc, avg_'.$los.'_profit desc '); //(candle_strength = '.(int)$candle_strengths[$los].') desc, 


				$knowledge = $knowledge->get();
//echo  print_r(DB::getQueryLog(), 1);
//				file_put_contents('/home/tom/results/wc_experiment_queries', print_r(DB::getQueryLog(), 1), FILE_APPEND);
				DB::disableQueryLog();

				if(count($knowledge)) {
					echo 'knowledge rows: ' ;
//					print_r( $knowledge);
					return ['signal' => $los, 'bounds_method'=>$knowledge[0]->bounds_strategy_name, 'knowledge_row'=>$knowledge[0]];
				}
			}
		}

		return ['signal' => 'none'];
	}

	/*use exact indicator match only*/
	function check_terry_knowledge3($pair, $indicators, $candles, $interval='1m', $long_bounds_methods, $short_bounds_methods) {
		$candle_strengths = CandleMap::get_candle_strengths($candles);

		$long_indicators = $short_indicators = [];
		foreach($indicators as $indicator_name => $indicator_value) {
			if($indicator_value > 0) {
				$long_indicators[] = $indicator_name;
			}
			else if($indicator_value < 0) {
				$short_indicators[] = $indicator_name;
			}
		}

		foreach(['long', 'short'] as $los) {
			$indicators = ${$los.'_indicators'};
			$bounds_methods = ${$los.'_bounds_methods'};
			echo $los . ' indicators: '.implode(',',array_values($indicators));
			$info = '.. '.count($indicators).' '.$los.' indicators and '.$los.'  candle strength '.$candle_strengths[$los].'...(worthy bounds methods: '.implode(',', $bounds_methods).')';
			echo $info;

			if(count($indicators) && $candle_strengths[$los] > 0) {
				sort($indicators);
				$indicator_combinations = [];
				// get strategy names (indicator combs) for IN clause
				$indicator_combinations[] = $indicators; // add full list, for potential exact match
				$strategy_names = [];
				foreach($indicator_combinations as $combination) {
					sort($combination);
					$strategy_names[] = 'exactmatch: '.implode('_', $combination);
				}

				DB::enableQueryLog();
				$knowledge = DB::table('terry_strategy_knowledge')
					->select(DB::raw('*'))
					->where('instrument', $pair)
					->where('interval', $interval)
					->where('percentage_'.$los.'_win', '>=', 70) // successful strats only
					->where('test_confirmations', '>=', 5) // with enough confirmations to be a valuable stat
//					->where('percentage_'.$los.'_win', '>=', 0) // successful strats only
//					->where('test_confirmations', '>=', 1) // with enough confirmations to be a valuable stat
					->where('candle_strength', '>', 0)
					->whereIn('strategy_name', $strategy_names) 
					->whereIn('bounds_method', $bounds_methods) 
//					->whereIn('candle_strength', $this->get_candle_strength_range($candle_strengths[$los]))
					->orderByRaw('avg_'.$los.'_profit desc '); //(candle_strength = '.(int)$candle_strengths[$los].') desc, 


				$knowledge = $knowledge->get();
//echo  print_r(DB::getQueryLog(), 1);
//				file_put_contents('/home/tom/results/wc_experiment_queries', print_r(DB::getQueryLog(), 1), FILE_APPEND);
				DB::disableQueryLog();

				if(count($knowledge)) {
					echo 'knowledge rows: ' ;
//					print_r( $knowledge);
					return ['signal' => $los, 'bounds_method'=>$knowledge[0]->bounds_strategy_name, 'knowledge_row'=>$knowledge[0], 'info'=>$info];
				}
			}
		}

		return ['signal' => 'none'];
	}

	public $bounds_methods = ['perc_10_20', 'perc_20_20', 'perc_30_30', 'perc_30_40', 'perc_40_40', 'fib_r1s1', 'fib_r2s2', 'fib_r3s3', 'demark'];
	function get_bounds($method, $data, $long, $current_price, $leverage) {
		switch ($method) {
			case 'perc_10_20':
				return $long ? [$current_price - round(($current_price * (10 / $leverage)) / 100, 5), $current_price + round(($current_price * (20 / $leverage)) / 100, 5)] : [$current_price + round(($current_price * (10 / $leverage)) / 100, 5), $current_price - round(($current_price * (20 / $leverage)) / 100, 5)];
			case 'perc_20_20':
				return $long ? [$current_price - round(($current_price * (20 / $leverage)) / 100, 5), $current_price + round(($current_price * (20 / $leverage)) / 100, 5)] : [$current_price + round(($current_price * (20 / $leverage)) / 100, 5), $current_price - round(($current_price * (20 / $leverage)) / 100, 5)];
			case 'perc_30_30':
				return $long ? [$current_price - round(($current_price * (30 / $leverage)) / 100, 5), $current_price + round(($current_price * (30 / $leverage)) / 100, 5)] : [$current_price + round(($current_price * (30 / $leverage)) / 100, 5), $current_price - round(($current_price * (30 / $leverage)) / 100, 5)];
			case 'perc_30_40':
				return $long ? [$current_price - round(($current_price * (30 / $leverage)) / 100, 5), $current_price + round(($current_price * (40 / $leverage)) / 100, 5)] : [$current_price + round(($current_price * (30 / $leverage)) / 100, 5), $current_price - round(($current_price * (40 / $leverage)) / 100, 5)];
			case 'perc_40_40':
				return $long ? [$current_price - round(($current_price * (40 / $leverage)) / 100, 5), $current_price + round(($current_price * (40 / $leverage)) / 100, 5)] : [$current_price + round(($current_price * (40 / $leverage)) / 100, 5), $current_price - round(($current_price * (40 / $leverage)) / 100, 5)];
			case 'fib_r1s1':
				$fibs = $this->calcFibonacci($data);
				return $long ? [$fibs['S1'], $fibs['R1']] : [$fibs['R1'], $fibs['S1']];
			case 'fib_r2s2':
				$fibs = $this->calcFibonacci($data);
				return $long ? [$fibs['S2'], $fibs['R2']] : [$fibs['R2'], $fibs['S2']];
			case 'fib_r3s3':
				$fibs = $this->calcFibonacci($data);
				return $long ? [$fibs['S3'], $fibs['R3']] : [$fibs['R3'], $fibs['S3']];
			case 'demark':
				$demark = $this->calcDemark($data);
				return $long ? [$demark['S1'], $demark['R1']] : [$demark['R1'], $demark['S1']];
			default:
				die('Invalid bounds method ' . $method);
		}
	}
	
	/**
	 * Return subset of bounds methods that are actually worth playing babsed on 
	 * current price, chart data and leverage.
	 * 
	 * ... for now use 4% increase/decrease as minimum
	 */
	function get_profitable_bounds_methods($current_price, $data, $leverage, $spread, $interval) {
		$profitable_long_bounds_methods = $profitable_short_bounds_methods = [];
//		$spread_price_range = (100 + (float)$spread) * $current_price;
//		
//		$long_entry = $current_price - $spread_price_range/2;
//		$short_entry = $current_price + $spread_price_range/2;
		
		$min_long_take_profit = $current_price + round(($current_price * (4 / $leverage)) / 100, 5);
		$max_long_stop_loss = $current_price - round(($current_price * (4 / $leverage)) / 100, 5);
		$max_short_take_profit = $current_price - round(($current_price * (4 / $leverage)) / 100, 5);
		$min_short_stop_loss = $current_price + round(($current_price * (4 / $leverage)) / 100, 5);
		
		echo "current priec: $current_price \n";
		foreach($this->bounds_methods as $bounds_method) {
			echo "bounds method: $bounds_method \n";
			if ($interval != '1m' && $interval != '5m' && $bounds_method == 'demark') { // only use demark for 1m and 5m
				continue;
			}
			list($stop_loss_long, $take_profit_long) = $this->get_bounds($bounds_method, $data, TRUE, $current_price, $leverage);
			echo "long stop take: $stop_loss_long, $take_profit_long     range: (".($take_profit_long-$stop_loss_long)." )\n";
			list($stop_loss_short, $take_profit_short) = $this->get_bounds($bounds_method, $data, FALSE, $current_price, $leverage);
			echo "short stop take: $stop_loss_short, $take_profit_short      range: (".($stop_loss_short-$take_profit_short)." ) \n";
			if($take_profit_long > $min_long_take_profit && $stop_loss_long < $max_long_stop_loss) {
				$profitable_long_bounds_methods[] = $bounds_method;
			}
			if($take_profit_short < $max_short_take_profit && $stop_loss_short > $min_short_stop_loss) {
				$profitable_short_bounds_methods[] = $bounds_method;
			}
		}
		return [$profitable_long_bounds_methods, $profitable_short_bounds_methods];
	}

	function combinations($arr, $level, &$result, $curr = array()) {
		for ($i = 0; $i < count($arr); $i++) {
			$new = array_merge($curr, array($arr[$i]));
			if ($level == 1) {
				sort($new);
				if (!in_array($new, $result) // unique entries only
						&& count(array_unique($new)) == count($new)/*distinct sets only*/) {
					$result[] = $new;
				}
			} else {
				$this->combinations($arr, $level - 1, $result, $new);
			}
		}
	}


	static function get_rules_for_interval($interval, $secs_since_market_open=PHP_INT_MAX) {
                               if ($interval == '1m') {
                                       $periods_to_get = min(floor($secs_since_market_open / 60) - 50, 365);
                                       $max_period = 60 * 8;
                                       $max_avg_period = 80;
                                       $interval_secs = 60;
                                       $min_periods = 300;
                               }
                               if ($interval == '5m') {
                                       $periods_to_get = min(floor($secs_since_market_open / (60 * 5)) - 25, 365);
                                       $max_period = 60 * 25;
                                       $max_avg_period = 60 * 8;
                                       $interval_secs = 5 * 60;
                                       $min_periods = 120;
                               }
                               if ($interval == '15m') {
                                       $periods_to_get = min(floor($secs_since_market_open / (60 * 15)) - 8, 365);
                                       $max_period = 60 * 25;
                                       $max_avg_period = 60 * 20;
                                       $interval_secs = 15 * 60;
                                       $min_periods = 50;
                               }
                               if ($interval == '30m') {
                                       $periods_to_get = min(floor($secs_since_market_open / (60 * 30)) - 4, 365);
                                       $max_period = 60 * 50;
                                       $max_avg_period = 60 * 40;
                                       $interval_secs = 30 * 60;
                                       $min_periods = 50;
                               }
                               if ($interval == '1h') {
                                       $periods_to_get = min(floor($secs_since_market_open / (60 * 60)), 365 - 2);
                                       $max_period = 60 * 120;
                                       $max_avg_period = 60 * 70;
                                       $interval_secs = 60 * 60;
                                       $min_periods = 40;
                               }
		return [$periods_to_get, $max_period, $max_avg_period, $interval_secs, $min_periods];
	}

}
