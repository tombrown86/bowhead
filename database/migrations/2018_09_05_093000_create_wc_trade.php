<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateWcTrade extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create('wc_trade', function(Blueprint $table) {

                        $table->increments('id');

                        $table->string('slug', 255)->index('slug1');
                        $table->string('direction', 10)->index('direction1');
                        $table->string('market', 10)->index('market1');
                        $table->integer('leverage')->index('leverage1');
                        $table->string('type', 10)->index('type1');
                        $table->string('state', 10)->index('state1');
                        $table->integer('size')->index('size1');
                        $table->integer('margin_size')->index('margin_size1');
                        $table->decimal('current_price', 20, 10)->index('current_price1');
                        $table->decimal('entry_price', 20, 10)->index('entry_price1');
                        $table->decimal('take_profit', 20, 10)->index('take_profit1');
                        $table->decimal('stop_loss', 20, 10)->index('stop_loss1');
                        $table->decimal('close_price', 20, 10)->index('close_price1');
                        $table->decimal('liquidation_price', 20, 10)->index('liquidation_price1');
                        $table->decimal('profit', 20, 5)->index('profit1');
                        $table->integer('trailing')->index('trailing1');
                        $table->integer('financing')->index('financing1');
                        $table->integer('trailing_distance')->index('trailing_distance1');

                        $table->string('close_reason', 15)->index('close_reason1');


                        $table->integer('created_at')->index('created_at1');
                        $table->integer('entered_at')->index('entered_at1');
                        $table->integer('closed_at')->index('closed_at1');
                        $table->string('currency', 15)->index('currency1');
                        $table->integer('err')->index('err1');


                        $table->string('terry_signal', 10)->index('terry_signal1');
                        $table->string('terry_bounds_method', 255)->index('terry_bounds_method1');
                        $table->text('terry_info');
                        $table->integer('terry_strategy_knowledge_id')->index('terry_strategy_knowledge_id1');



			$table->softDeletes();
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::drop('wc_trade');
	}

}
