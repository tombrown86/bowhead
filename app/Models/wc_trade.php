<?php

namespace Bowhead\Models;

use Illuminate\Database\Eloquent\Model;


class wc_trade extends Model
{


    protected $table = 'wc_trade';
    public $timestamps = false;
    /**
     * @var array
     */
    protected $fillable = ['id','slug','direction','market','leverage','type','state','size','margin_size','entry_price','take_profit','stop_loss','close_price','liquidation_price','profit','trailing','trailing_distance','financing','close_reason','created_at','entered_at','closed_at','currency','err','terry_signal','terry_bounds_method','terry_info','terry_strategy_knowledge_id',];
}
