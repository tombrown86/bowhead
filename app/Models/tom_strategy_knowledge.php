<?php

namespace Bowhead\Models;

use Illuminate\Database\Eloquent\Model;


class tom_strategy_knowledge extends Model
{


    protected $table = 'tom_strategy_knowledge';

    /**
     * @var array
     */
    protected $fillable = ['id', 'strategy_name', 'bounds_strategy_name', 'indicator_count', 'instrument', 'percentage_win', 'percentage_long_win', 'percentage_short_win' ,'avg_stop_take_range', 'avg_long_profit', 'avg_short_profit', 'avg_profit', 'long_wins_per_year', 'short_wins_per_year', 'long_loses_per_year', 'short_loses_per_year', 'timeout_loses_per_year', 'longs_per_year', 'shorts_per_year'];

}
