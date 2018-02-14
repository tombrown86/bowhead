<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateTomStrategyKnowledge extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('tom_strategy_knowledge', function(Blueprint $table)
		{
			$table->increments('id');

                      $table->string('strategy_name', 255)->index('strategy_name1');
                        $table->string('bounds_strategy_name', 255)->index('bounds_strategy_name1');
                        $table->string('instrument', 20)->index('instrument1');
                        $table->decimal('percentage_win')->index('percentage_win1');
                        $table->decimal('percentage_long_win')->index('percentage_long_win1');
                        $table->decimal('percentage_short_win')->index('percentage_short_win1');
                        $table->decimal('avg_stop_take_range', 30, 15)->index('avg_stop_take_range1');
                        $table->decimal('avg_long_profit', 30, 15)->index('avg_long_profit1');
                        $table->decimal('avg_short_profit', 30, 15)->index('avg_short_profit1');
                        $table->decimal('avg_profit', 30, 15)->index('avg_profit1');
                        $table->decimal('long_wins_per_year', 30, 5)->index('long_wins_per_year1');
                        $table->decimal('short_wins_per_year', 30, 5)->index('short_wins_per_year1');
                        $table->decimal('long_loses_per_year', 30, 5)->index('long_loses_per_year1');
                        $table->decimal('short_loses_per_year', 30, 5)->index('short_loses_per_year1');
                        $table->decimal('timeout_loses_per_year', 30, 5)->index('timeout_loses_per_year1');
                        $table->decimal('longs_per_year', 30, 5)->index('longs_per_year1');
                        $table->decimal('shorts_per_year', 30, 5)->index('shorts_per_year1');
                        $table->integer('indicator_count')->index('indicator_count1');

			$table->unique(['strategy_name', 'bounds_strategy_name', 'instrument'], 'unique_strat_technique');



			$table->timestamps();
			$table->softDeletes();
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('tom_strategy_knowledge');
	}

}
