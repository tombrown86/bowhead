<?php

namespace Bowhead\Models;

use Illuminate\Database\Eloquent\Model;


class terry_strategy_knowledge extends Model
{


    protected $table = 'terry_strategy_knowledge';

    /**
     * @var array
     */
    protected $fillable = ['id', 'strategy_name', 'bounds_strategy_name', 'indicator_count', 'instrument', 'percentage_win', 'percentage_long_win', 'percentage_short_win' ,'avg_stop_take_range', 'avg_long_profit', 'avg_short_profit', 'avg_profit', 'long_wins_per_day', 'short_wins_per_day', 'long_loses_per_day', 'short_loses_per_day', 'timeout_loses_per_day', 'longs_per_day', 'shorts_per_day', 'test_confirmations', 'candle_count'];

}
